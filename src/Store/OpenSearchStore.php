<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Store;

use Illuminate\Http\Client\Response;
use Kipchak\Driver\Vector\Exception\CollectionNotFoundException;
use Kipchak\Driver\Vector\Exception\VectorException;
use Kipchak\Driver\Vector\Query\Distance;
use Kipchak\Driver\Vector\Query\Filter;
use Kipchak\Driver\Vector\Result\CollectionInfo;
use Kipchak\Driver\Vector\Result\Record;
use Kipchak\Driver\Vector\Result\ScoredPoint;

/**
 * OpenSearch's k-NN plugin over the REST API: one index per collection, with the vector in a knn_vector field
 * and the payload under "payload", its strings mapped as keywords so filters match them exactly.
 *
 * OpenSearch rescales scores to be positive; they are converted back to the similarities and distances Distance
 * describes.
 */
final class OpenSearchStore implements VectorStore
{
    private const array SPACES = [
        'cosine' => 'cosinesimil',
        'dot' => 'innerproduct',
        'euclidean' => 'l2',
    ];

    private const int MIN_CANDIDATES = 100;

    /** @var array<string, Distance> Each collection's distance, read once per worker. */
    private array $distances = [];

    public function __construct(
        private readonly JsonHttp $http,
        private readonly string $indexPrefix = '',
        private readonly string $engine = 'lucene',
        private readonly string $refresh = 'wait_for',
    ) {
    }

    /**
     * Builds the exception for an OpenSearch error response: {"error": {"type": "...", "reason": "..."}}.
     */
    public static function error(Response $response): VectorException
    {
        $error = $response->json('error');
        $type = is_array($error) ? ($error['type'] ?? null) : null;
        $reason = is_array($error) ? ($error['reason'] ?? null) : (is_string($error) ? $error : null);

        if ($type === 'index_not_found_exception') {
            $index = is_array($error) ? (string) ($error['index'] ?? '') : '';

            return CollectionNotFoundException::named($index);
        }

        return new VectorException('[OpenSearch] ' . ($reason ?? 'HTTP ' . $response->status()), $response->status(), is_string($type) ? $type : null);
    }

    public function createCollection(string $collection, int $dimensions, Distance $distance): void
    {
        $this->http->request('PUT', $this->path($collection), [
            'settings' => ['index' => ['knn' => true]],
            'mappings' => [
                '_meta' => ['kipchak' => ['distance' => $distance->value]],
                'dynamic_templates' => [[
                    'payload_strings' => [
                        'path_match' => 'payload.*',
                        'match_mapping_type' => 'string',
                        'mapping' => ['type' => 'keyword'],
                    ],
                ]],
                'properties' => [
                    'vector' => [
                        'type' => 'knn_vector',
                        'dimension' => $dimensions,
                        'method' => ['name' => 'hnsw', 'space_type' => self::SPACES[$distance->value], 'engine' => $this->engine],
                    ],
                    'payload' => ['type' => 'object'],
                ],
            ],
        ]);
        $this->distances[$collection] = $distance;
    }

    public function collectionExists(string $collection): bool
    {
        $response = $this->http->send('HEAD', $this->path($collection));
        if ($response->status() === 404) {
            return false;
        }
        if ($response->failed()) {
            throw new VectorException('[OpenSearch] HTTP ' . $response->status(), $response->status());
        }

        return true;
    }

    public function deleteCollection(string $collection): void
    {
        $this->http->request('DELETE', $this->path($collection));
        unset($this->distances[$collection]);
    }

    public function info(string $collection): CollectionInfo
    {
        $mapping = $this->http->request('GET', $this->path($collection) . '/_mapping');
        $mappings = reset($mapping)['mappings'] ?? [];
        $distance = Distance::tryFrom((string) ($mappings['_meta']['kipchak']['distance'] ?? ''))
            ?? (Distance::tryFrom((string) array_search($mappings['properties']['vector']['method']['space_type'] ?? '', self::SPACES, true)) ?? Distance::Cosine);
        $this->distances[$collection] = $distance;

        return new CollectionInfo(
            name: $collection,
            dimensions: (int) ($mappings['properties']['vector']['dimension'] ?? 0),
            distance: $distance,
            count: $this->count($collection, null),
        );
    }

    public function upsert(string $collection, array $points): void
    {
        // A bulk request would create a missing index with the wrong mapping, so the collection is checked first
        // (once per worker).
        $this->distance($collection);
        $index = $this->indexName($collection);
        $lines = '';
        foreach ($points as $point) {
            $lines .= json_encode(['index' => ['_index' => $index, '_id' => $point->id]], JSON_THROW_ON_ERROR) . "\n";
            $lines .= json_encode(
                ['vector' => $point->vector, 'payload' => (object) $point->payload],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            ) . "\n";
        }

        $this->bulk($collection, $lines);
    }

    public function search(string $collection, array $vector, int $limit, ?Filter $filter, ?float $threshold, bool $withVectors): array
    {
        $distance = $this->distance($collection);
        // The Lucene engine also uses k as the breadth of its graph search, so a small k misses close points.
        $knn = ['vector' => $vector, 'k' => min(10000, max($limit, self::MIN_CANDIDATES))];
        if ($filter !== null) {
            $knn['filter'] = self::filter($filter);
        }

        $hits = $this->http->request('POST', $this->path($collection) . '/_search', [
            'size' => $limit,
            'query' => ['knn' => ['vector' => $knn]],
            '_source' => $withVectors ? true : ['excludes' => ['vector']],
        ])['hits']['hits'] ?? [];

        $matches = array_map(static fn (array $hit): ScoredPoint => new ScoredPoint(
            (string) $hit['_id'],
            self::score($distance, (float) $hit['_score']),
            self::payload($hit),
            $withVectors ? self::vectorOf($hit) : null,
        ), $hits);

        return $threshold === null ? $matches : array_values(array_filter(
            $matches,
            static fn (ScoredPoint $match): bool => $distance->passes($match->score, $threshold),
        ));
    }

    public function get(string $collection, array $ids, bool $withVectors): array
    {
        $docs = $this->http->request(
            'POST',
            $this->path($collection) . '/_mget',
            ['ids' => $ids],
            $withVectors ? [] : ['_source_excludes' => 'vector'],
        )['docs'] ?? [];

        $records = [];
        foreach ($docs as $doc) {
            if (($doc['found'] ?? false) === true) {
                $records[] = new Record((string) $doc['_id'], self::payload($doc), $withVectors ? self::vectorOf($doc) : null);
            }
        }

        return $records;
    }

    public function delete(string $collection, array $ids): void
    {
        $index = $this->indexName($collection);
        $lines = '';
        foreach ($ids as $id) {
            $lines .= json_encode(['delete' => ['_index' => $index, '_id' => $id]], JSON_THROW_ON_ERROR) . "\n";
        }

        $this->bulk($collection, $lines);
    }

    public function deleteWhere(string $collection, Filter $filter): void
    {
        $this->http->request(
            'POST',
            $this->path($collection) . '/_delete_by_query',
            ['query' => self::filter($filter)],
            ['refresh' => 'true', 'conflicts' => 'proceed'],
        );
    }

    public function count(string $collection, ?Filter $filter): int
    {
        $body = $filter === null ? null : ['query' => self::filter($filter)];

        return (int) ($this->http->request('POST', $this->path($collection) . '/_count', $body ?? new \stdClass())['count'] ?? 0);
    }

    public function health(): bool
    {
        try {
            $this->http->request('GET', '/_cluster/health');

            return true;
        } catch (VectorException) {
            return false;
        }
    }

    /**
     * OpenSearch's query for a Filter, on the fields under "payload".
     *
     * @return array<string, mixed>
     */
    public static function filter(Filter $filter): array
    {
        return match ($filter->type) {
            Filter::AND => ['bool' => ['filter' => array_map(self::filter(...), $filter->filters)]],
            Filter::OR => ['bool' => ['should' => array_map(self::filter(...), $filter->filters), 'minimum_should_match' => 1]],
            Filter::NOT => ['bool' => ['must_not' => [self::filter($filter->filters[0])]]],
            Filter::EQUALS => ['term' => ['payload.' . $filter->field => $filter->values[0]]],
            Filter::IN => ['terms' => ['payload.' . $filter->field => $filter->values]],
            Filter::RANGE => ['range' => ['payload.' . $filter->field => $filter->bounds]],
        };
    }

    /**
     * Converts OpenSearch's score back to the similarity or distance: cosinesimil scores (1 + cos) / 2, l2 scores
     * 1 / (1 + d²), and innerproduct scores dot + 1 for a positive dot product and 1 / (1 - dot) otherwise.
     */
    public static function score(Distance $distance, float $score): float
    {
        return match ($distance) {
            Distance::Cosine => 2 * $score - 1,
            Distance::Euclidean => $score > 0 ? sqrt(max(0.0, 1 / $score - 1)) : INF,
            Distance::Dot => $score >= 1 ? $score - 1 : 1 - 1 / $score,
        };
    }

    private function bulk(string $collection, string $lines): void
    {
        if ($lines === '') {
            return;
        }

        $result = $this->http->request('POST', '/_bulk', query: ['refresh' => $this->refresh], rawBody: $lines, contentType: 'application/x-ndjson');
        if (($result['errors'] ?? false) !== true) {
            return;
        }

        foreach ($result['items'] ?? [] as $item) {
            $outcome = reset($item);
            $error = $outcome['error'] ?? null;
            if (is_array($error)) {
                if (($error['type'] ?? null) === 'index_not_found_exception') {
                    throw CollectionNotFoundException::named($collection);
                }
                throw new VectorException('[OpenSearch] ' . ($error['reason'] ?? 'Bulk request failed'), (int) ($outcome['status'] ?? 0), $error['type'] ?? null);
            }
        }
    }

    private function distance(string $collection): Distance
    {
        return $this->distances[$collection] ?? $this->info($collection)->distance;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(array $hit): array
    {
        $payload = $hit['_source']['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return list<float>|null
     */
    private static function vectorOf(array $hit): ?array
    {
        $vector = $hit['_source']['vector'] ?? null;

        return is_array($vector) ? array_map('floatval', $vector) : null;
    }

    private function indexName(string $collection): string
    {
        return $this->indexPrefix . $collection;
    }

    private function path(string $collection): string
    {
        return '/' . rawurlencode($this->indexName($collection));
    }
}
