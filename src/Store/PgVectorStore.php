<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Kipchak\Driver\Vector\Exception\CollectionNotFoundException;
use Kipchak\Driver\Vector\Exception\VectorConnectionException;
use Kipchak\Driver\Vector\Exception\VectorException;
use Kipchak\Driver\Vector\Query\Distance;
use Kipchak\Driver\Vector\Query\Filter;
use Kipchak\Driver\Vector\Result\CollectionInfo;
use Kipchak\Driver\Vector\Result\Record;
use Kipchak\Driver\Vector\Result\ScoredPoint;

/**
 * PostgreSQL with the pgvector extension, on a Doctrine DBAL connection from the Doctrine driver.
 *
 * Each collection is a table, "<prefix><collection>" (id text, embedding vector(n), payload jsonb), with an HNSW
 * index for its distance and a GIN index on the payload. A registry table, "<prefix>collections", records each
 * collection's dimensions and distance. Vectors with more than 2,000 dimensions (HNSW's limit for vector) are
 * indexed and searched as halfvec.
 *
 * Filtered searches use pgvector's iterative index scans (0.8 and later), so a selective filter still returns
 * up to the limit.
 */
final class PgVectorStore implements VectorStore
{
    private const int HNSW_MAX_DIMENSIONS = 2000;

    private const array OPERATORS = [
        'cosine' => '<=>',
        'dot' => '<#>',
        'euclidean' => '<->',
    ];

    private const array OPERATOR_CLASSES = [
        'cosine' => 'cosine_ops',
        'dot' => 'ip_ops',
        'euclidean' => 'l2_ops',
    ];

    /** @var array<string, array{dimensions: int, distance: Distance}> */
    private array $collections = [];

    private ?bool $iterativeScans = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tablePrefix = 'vector_',
    ) {
    }

    public function createCollection(string $collection, int $dimensions, Distance $distance): void
    {
        $this->run(function () use ($collection, $dimensions, $distance): void {
            $this->ensureExtension();
            $this->connection->transactional(function (Connection $connection) use ($collection, $dimensions, $distance): void {
                $this->ensureRegistry();
                $table = $this->table($collection);
                $connection->executeStatement(sprintf(
                    'CREATE TABLE %s (id text PRIMARY KEY, embedding vector(%d) NOT NULL, payload jsonb NOT NULL DEFAULT \'{}\')',
                    $table,
                    $dimensions,
                ));
                $connection->executeStatement(sprintf(
                    'CREATE INDEX %s ON %s USING hnsw (%s %s)',
                    $this->identifier($this->tablePrefix . $collection . '_embedding'),
                    $table,
                    $this->indexedColumn($dimensions),
                    ($dimensions > self::HNSW_MAX_DIMENSIONS ? 'halfvec_' : 'vector_') . self::OPERATOR_CLASSES[$distance->value],
                ));
                $connection->executeStatement(sprintf(
                    'CREATE INDEX %s ON %s USING gin (payload jsonb_path_ops)',
                    $this->identifier($this->tablePrefix . $collection . '_payload'),
                    $table,
                ));
                $connection->insert($this->tablePrefix . 'collections', [
                    'name' => $collection,
                    'dimensions' => $dimensions,
                    'distance' => $distance->value,
                ]);
            });
        }, $collection);
        $this->collections[$collection] = ['dimensions' => $dimensions, 'distance' => $distance];
    }

    public function collectionExists(string $collection): bool
    {
        return $this->run(function () use ($collection): bool {
            if (!$this->registryExists()) {
                return false;
            }

            return $this->connection->fetchOne(
                'SELECT 1 FROM ' . $this->identifier($this->tablePrefix . 'collections') . ' WHERE name = ?',
                [$collection],
            ) !== false;
        }, $collection);
    }

    public function deleteCollection(string $collection): void
    {
        $this->collection($collection);
        $this->run(function () use ($collection): void {
            $this->connection->transactional(function (Connection $connection) use ($collection): void {
                $connection->executeStatement('DROP TABLE IF EXISTS ' . $this->table($collection));
                $connection->delete($this->tablePrefix . 'collections', ['name' => $collection]);
            });
        }, $collection);
        unset($this->collections[$collection]);
    }

    public function info(string $collection): CollectionInfo
    {
        $details = $this->collection($collection);

        return new CollectionInfo($collection, $details['dimensions'], $details['distance'], $this->count($collection, null));
    }

    public function upsert(string $collection, array $points): void
    {
        if ($points === []) {
            return;
        }
        $this->collection($collection);

        $rows = [];
        $params = [];
        foreach ($points as $point) {
            $rows[] = '(?, ?::vector, ?::jsonb)';
            array_push(
                $params,
                $point->id,
                self::vectorLiteral($point->vector),
                json_encode((object) $point->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            );
        }

        $this->run(fn () => $this->connection->executeStatement(
            'INSERT INTO ' . $this->table($collection) . ' (id, embedding, payload) VALUES ' . implode(', ', $rows)
            . ' ON CONFLICT (id) DO UPDATE SET embedding = EXCLUDED.embedding, payload = EXCLUDED.payload',
            $params,
        ), $collection);
    }

    public function search(string $collection, array $vector, int $limit, ?Filter $filter, ?float $threshold, bool $withVectors): array
    {
        $details = $this->collection($collection);
        $distance = $details['distance'];
        $column = $this->indexedColumn($details['dimensions']);
        $operator = self::OPERATORS[$distance->value];
        $cast = $details['dimensions'] > self::HNSW_MAX_DIMENSIONS ? '::halfvec(' . $details['dimensions'] . ')' : '::vector';

        $params = [self::vectorLiteral($vector)];
        $where = '';
        if ($filter !== null) {
            $where = ' WHERE ' . self::filter($filter, $params);
        }
        $params[] = self::vectorLiteral($vector);
        $params[] = $limit;

        $select = sprintf(
            'SELECT id, payload, %s %s ?%s AS distance%s FROM %s%s ORDER BY %s %s ?%s LIMIT ?',
            $column,
            $operator,
            $cast,
            $withVectors ? ', embedding::text AS embedding' : '',
            $this->table($collection),
            $where,
            $column,
            $operator,
            $cast,
        );

        $rows = $this->run(function () use ($select, $params, $filter): array {
            if ($filter === null || !$this->iterativeScans()) {
                return $this->connection->fetchAllAssociative($select, $params);
            }

            // Iterative scans keep reading the index until enough rows pass the filter. relaxed_order may return
            // them slightly out of order, so they are sorted again.
            return $this->connection->transactional(function (Connection $connection) use ($select, $params): array {
                $connection->executeStatement("SET LOCAL hnsw.iterative_scan = 'relaxed_order'");

                return $connection->fetchAllAssociative('WITH matches AS MATERIALIZED (' . $select . ') SELECT * FROM matches ORDER BY distance', $params);
            });
        }, $collection);

        $matches = array_map(static fn (array $row): ScoredPoint => new ScoredPoint(
            (string) $row['id'],
            self::score($distance, (float) $row['distance']),
            self::decodePayload($row['payload']),
            $withVectors ? self::parseVector((string) $row['embedding']) : null,
        ), $rows);

        return $threshold === null ? $matches : array_values(array_filter(
            $matches,
            static fn (ScoredPoint $match): bool => $distance->passes($match->score, $threshold),
        ));
    }

    public function get(string $collection, array $ids, bool $withVectors): array
    {
        if ($ids === []) {
            return [];
        }
        $this->collection($collection);

        $rows = $this->run(fn () => $this->connection->fetchAllAssociative(
            'SELECT id, payload' . ($withVectors ? ', embedding::text AS embedding' : '') . ' FROM ' . $this->table($collection)
            . ' WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids,
        ), $collection);

        return array_map(static fn (array $row): Record => new Record(
            (string) $row['id'],
            self::decodePayload($row['payload']),
            $withVectors ? self::parseVector((string) $row['embedding']) : null,
        ), $rows);
    }

    public function delete(string $collection, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->collection($collection);

        $this->run(fn () => $this->connection->executeStatement(
            'DELETE FROM ' . $this->table($collection) . ' WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids,
        ), $collection);
    }

    public function deleteWhere(string $collection, Filter $filter): void
    {
        $this->collection($collection);
        $params = [];
        $where = self::filter($filter, $params);

        $this->run(fn () => $this->connection->executeStatement('DELETE FROM ' . $this->table($collection) . ' WHERE ' . $where, $params), $collection);
    }

    public function count(string $collection, ?Filter $filter): int
    {
        $this->collection($collection);
        $params = [];
        $where = $filter === null ? '' : ' WHERE ' . self::filter($filter, $params);

        return (int) $this->run(fn () => $this->connection->fetchOne('SELECT count(*) FROM ' . $this->table($collection) . $where, $params), $collection);
    }

    public function health(): bool
    {
        try {
            return $this->connection->fetchOne('SELECT 1') == 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The SQL condition for a Filter on the payload column, adding its values to $params.
     *
     * equals and in use jsonb containment (served by the GIN index), matching the value itself or an array that
     * contains it; range compares numbers only.
     *
     * @param list<mixed> $params
     */
    public static function filter(Filter $filter, array &$params): string
    {
        switch ($filter->type) {
            case Filter::AND:
            case Filter::OR:
                $parts = [];
                foreach ($filter->filters as $child) {
                    $parts[] = self::filter($child, $params);
                }

                return '(' . implode($filter->type === Filter::AND ? ' AND ' : ' OR ', $parts) . ')';

            case Filter::NOT:
                return '(NOT COALESCE(' . self::filter($filter->filters[0], $params) . ', false))';

            case Filter::EQUALS:
            case Filter::IN:
                $parts = [];
                foreach ($filter->values as $value) {
                    $parts[] = 'payload @> ?::jsonb';
                    $params[] = self::containment($filter->path(), $value);
                    $parts[] = 'payload @> ?::jsonb';
                    $params[] = self::containment($filter->path(), [$value]);
                }

                return '(' . implode(' OR ', $parts) . ')';

            default:
                $path = '{' . implode(',', $filter->path()) . '}';
                $number = "(CASE WHEN jsonb_typeof(payload #> ?::text[]) = 'number' THEN (payload #>> ?::text[])::numeric END)";
                $parts = [];
                foreach ($filter->bounds as $bound => $value) {
                    $parts[] = $number . ' ' . ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$bound] . ' ?';
                    array_push($params, $path, $path, $value);
                }

                return '(' . implode(' AND ', $parts) . ')';
        }
    }

    /**
     * Converts pgvector's operator result to a score: <=> gives the cosine distance (1 - similarity), <#> the
     * negative inner product, and <-> the Euclidean distance.
     */
    public static function score(Distance $distance, float $value): float
    {
        return match ($distance) {
            Distance::Cosine => 1 - $value,
            Distance::Dot => -$value,
            Distance::Euclidean => $value,
        };
    }

    /**
     * @param list<float> $vector
     */
    public static function vectorLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(static fn (float $value): string => var_export($value, true), $vector)) . ']';
    }

    /**
     * @return list<float>
     */
    public static function parseVector(string $literal): array
    {
        $values = trim($literal, '[] ');

        return $values === '' ? [] : array_map('floatval', explode(',', $values));
    }

    /**
     * @param list<string> $path
     */
    private static function containment(array $path, mixed $value): string
    {
        foreach (array_reverse($path) as $key) {
            $value = [$key => $value];
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodePayload(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{dimensions: int, distance: Distance}
     */
    private function collection(string $collection): array
    {
        if (isset($this->collections[$collection])) {
            return $this->collections[$collection];
        }

        $row = $this->run(function () use ($collection): array|false {
            if (!$this->registryExists()) {
                return false;
            }

            return $this->connection->fetchAssociative(
                'SELECT dimensions, distance FROM ' . $this->identifier($this->tablePrefix . 'collections') . ' WHERE name = ?',
                [$collection],
            );
        }, $collection);
        if ($row === false) {
            throw CollectionNotFoundException::named($collection);
        }

        return $this->collections[$collection] = ['dimensions' => (int) $row['dimensions'], 'distance' => Distance::from((string) $row['distance'])];
    }

    private function indexedColumn(int $dimensions): string
    {
        return $dimensions > self::HNSW_MAX_DIMENSIONS ? '(embedding::halfvec(' . $dimensions . '))' : 'embedding';
    }

    private function ensureExtension(): void
    {
        if ($this->connection->fetchOne("SELECT 1 FROM pg_extension WHERE extname = 'vector'") !== false) {
            return;
        }

        try {
            $this->connection->executeStatement('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (DbalException $exception) {
            throw new VectorException(
                'The pgvector extension is not installed in this database, and this user may not create it. Run CREATE EXTENSION vector as a superuser. ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function ensureRegistry(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ' . $this->identifier($this->tablePrefix . 'collections')
            . ' (name text PRIMARY KEY, dimensions integer NOT NULL, distance text NOT NULL)'
        );
    }

    private function registryExists(): bool
    {
        return $this->connection->fetchOne('SELECT to_regclass(?)', [$this->identifier($this->tablePrefix . 'collections')]) !== null;
    }

    private function iterativeScans(): bool
    {
        return $this->iterativeScans ??= version_compare(
            (string) $this->connection->fetchOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'"),
            '0.8.0',
            '>=',
        );
    }

    private function table(string $collection): string
    {
        return $this->identifier($this->tablePrefix . $collection);
    }

    private function identifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Runs a database call, turning DBAL's exceptions into the driver's.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function run(callable $work, string $collection): mixed
    {
        try {
            try {
                return $work();
            } catch (ConnectionException $exception) {
                // A worker's connection dies when PostgreSQL restarts or drops idle connections. Every operation
                // here can safely run twice, so it is retried once on a new connection, unless a transaction the
                // caller began is open.
                if ($this->connection->isTransactionActive()) {
                    throw $exception;
                }
                $this->connection->close();

                return $work();
            }
        } catch (TableNotFoundException $exception) {
            unset($this->collections[$collection]);
            throw CollectionNotFoundException::named($collection, $exception);
        } catch (ConnectionException $exception) {
            throw new VectorConnectionException('[pgvector] ' . $exception->getMessage(), previous: $exception);
        } catch (DbalException $exception) {
            $sqlState = method_exists($exception, 'getSQLState') ? $exception->getSQLState() : null;
            throw new VectorException('[pgvector] ' . $exception->getMessage(), storeError: $sqlState, previous: $exception);
        }
    }
}
