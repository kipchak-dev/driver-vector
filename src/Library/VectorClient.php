<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Library;

use InvalidArgumentException;
use Kipchak\Driver\Vector\Embedding\Document;
use Kipchak\Driver\Vector\Embedding\Embedder;
use Kipchak\Driver\Vector\Exception\CollectionNotFoundException;
use Kipchak\Driver\Vector\Exception\VectorException;
use Kipchak\Driver\Vector\Query\Distance;
use Kipchak\Driver\Vector\Query\Filter;
use Kipchak\Driver\Vector\Result\CollectionInfo;
use Kipchak\Driver\Vector\Result\Point;
use Kipchak\Driver\Vector\Result\Record;
use Kipchak\Driver\Vector\Result\ScoredPoint;
use Kipchak\Driver\Vector\Store\VectorStore;

/**
 * A vector store connection: the same methods whichever store (Qdrant, pgvector or OpenSearch) it uses.
 *
 * Holds no per-request state, so one instance is safe to share across requests in FrankenPHP worker mode.
 */
final class VectorClient
{
    private const string COLLECTION = '/^[a-z0-9][a-z0-9_-]{0,62}$/';

    public function __construct(
        private readonly VectorStore $store,
        private readonly string $connectionName,
        private readonly int $batchSize = 256,
        private readonly ?Embedder $embedder = null,
        private readonly string $textField = 'text',
        private readonly int $embeddingBatchSize = 100,
    ) {
    }

    // -------------------------------------------------------------------------
    // Collections
    // -------------------------------------------------------------------------

    /**
     * Creates a collection for vectors of $dimensions numbers, compared by $distance.
     *
     * @throws VectorException When it already exists, among other errors.
     */
    public function createCollection(string $collection, int $dimensions, Distance $distance = Distance::Cosine): void
    {
        if ($dimensions < 1 || $dimensions > 65536) {
            throw new InvalidArgumentException('A collection needs between 1 and 65,536 dimensions; got ' . $dimensions . '.');
        }
        $this->store->createCollection(self::name($collection), $dimensions, $distance);
    }

    /**
     * Creates the collection unless it exists. Returns whether it was created.
     */
    public function ensureCollection(string $collection, int $dimensions, Distance $distance = Distance::Cosine): bool
    {
        if ($this->collectionExists($collection)) {
            return false;
        }
        $this->createCollection($collection, $dimensions, $distance);

        return true;
    }

    public function collectionExists(string $collection): bool
    {
        return $this->store->collectionExists(self::name($collection));
    }

    /**
     * Deletes the collection and every point in it. Does nothing if there is no such collection.
     */
    public function deleteCollection(string $collection): void
    {
        if ($this->collectionExists($collection)) {
            $this->store->deleteCollection(self::name($collection));
        }
    }

    /**
     * @throws CollectionNotFoundException
     */
    public function info(string $collection): CollectionInfo
    {
        return $this->store->info(self::name($collection));
    }

    // -------------------------------------------------------------------------
    // Points
    // -------------------------------------------------------------------------

    /**
     * Inserts points, or replaces those with the same ID, in batches of batch_size. Each point is a Point or an
     * array with 'id', 'vector' and optionally 'payload'. Returns how many points were written.
     *
     * @param iterable<Point|array{id: string|int, vector: list<float>, payload?: array<string, mixed>}> $points
     * @throws CollectionNotFoundException
     */
    public function upsert(string $collection, iterable $points): int
    {
        $collection = self::name($collection);
        $batch = [];
        $written = 0;
        foreach ($points as $point) {
            $batch[] = self::point($point);
            if (count($batch) === $this->batchSize) {
                $this->store->upsert($collection, $batch);
                $written += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->store->upsert($collection, $batch);
            $written += count($batch);
        }

        return $written;
    }

    /**
     * The points closest to $vector, closest first.
     *
     * @param list<int|float> $vector
     * @param float|null $threshold Leave out matches scoring below it (Cosine, Dot) or above it (Euclidean).
     * @return list<ScoredPoint>
     * @throws CollectionNotFoundException
     */
    public function search(string $collection, array $vector, int $limit = 10, ?Filter $filter = null, ?float $threshold = null, bool $withVectors = false): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The search limit must be at least 1.');
        }

        return $this->store->search(self::name($collection), Point::vector($vector), $limit, $filter, $threshold, $withVectors);
    }

    /**
     * The points with these IDs that exist, in the order asked for.
     *
     * @param list<string|int> $ids
     * @return list<Record>
     */
    public function get(string $collection, array $ids, bool $withVectors = false): array
    {
        $ids = array_values(array_unique(array_map(Point::id(...), $ids)));
        if ($ids === []) {
            return [];
        }

        $records = [];
        foreach ($this->store->get(self::name($collection), $ids, $withVectors) as $record) {
            $records[$record->id] = $record;
        }

        return array_values(array_filter(array_map(static fn (string $id): ?Record => $records[$id] ?? null, $ids)));
    }

    public function find(string $collection, string|int $id, bool $withVectors = false): ?Record
    {
        return $this->get($collection, [$id], $withVectors)[0] ?? null;
    }

    /**
     * Deletes the points with these IDs. IDs that do not exist are ignored.
     *
     * @param list<string|int> $ids
     */
    public function delete(string $collection, array $ids): void
    {
        $ids = array_values(array_unique(array_map(Point::id(...), $ids)));
        foreach (array_chunk($ids, $this->batchSize) as $chunk) {
            $this->store->delete(self::name($collection), $chunk);
        }
    }

    /**
     * Deletes every point whose payload matches the filter.
     */
    public function deleteWhere(string $collection, Filter $filter): void
    {
        $this->store->deleteWhere(self::name($collection), $filter);
    }

    public function count(string $collection, ?Filter $filter = null): int
    {
        return $this->store->count(self::name($collection), $filter);
    }

    // -------------------------------------------------------------------------
    // Text, with the connection's embeddings
    // -------------------------------------------------------------------------

    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        return $this->embedder()->embed([$text])[0];
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedMany(array $texts): array
    {
        $vectors = [];
        foreach (array_chunk(array_values($texts), $this->embeddingBatchSize) as $chunk) {
            array_push($vectors, ...$this->embedder()->embed($chunk));
        }

        return $vectors;
    }

    /**
     * Embeds texts and stores them, with each text in its payload under the text field. Each document is a
     * Document or an array with 'id', 'text' and optionally 'payload'. Returns how many were written.
     *
     * @param iterable<Document|array{id: string|int, text: string, payload?: array<string, mixed>}> $documents
     */
    public function upsertTexts(string $collection, iterable $documents): int
    {
        $written = 0;
        $batch = [];
        foreach ($documents as $document) {
            $batch[] = self::document($document);
            if (count($batch) === $this->embeddingBatchSize) {
                $written += $this->upsertDocuments($collection, $batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $written += $this->upsertDocuments($collection, $batch);
        }

        return $written;
    }

    /**
     * Embeds the text and returns the closest points, as search() does.
     *
     * @return list<ScoredPoint>
     */
    public function searchText(string $collection, string $text, int $limit = 10, ?Filter $filter = null, ?float $threshold = null): array
    {
        return $this->search($collection, $this->embed($text), $limit, $filter, $threshold);
    }

    // -------------------------------------------------------------------------
    // Diagnostics
    // -------------------------------------------------------------------------

    /**
     * Whether the store answers. Never throws.
     */
    public function health(): bool
    {
        return $this->store->health();
    }

    public function store(): VectorStore
    {
        return $this->store;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param list<Document> $documents
     */
    private function upsertDocuments(string $collection, array $documents): int
    {
        $vectors = $this->embedder()->embed(array_map(static fn (Document $document): string => $document->text, $documents));

        return $this->upsert($collection, array_map(
            fn (Document $document, array $vector): Point => new Point($document->id, $vector, [$this->textField => $document->text] + $document->payload),
            $documents,
            $vectors,
        ));
    }

    private function embedder(): Embedder
    {
        return $this->embedder ?? throw new InvalidArgumentException(
            'Vector connection "' . $this->connectionName . '" has no embeddings configured; set its "embeddings" to use text methods.'
        );
    }

    private static function point(mixed $point): Point
    {
        if ($point instanceof Point) {
            return $point;
        }
        if (!is_array($point) || !isset($point['id'], $point['vector']) || !is_array($point['vector'])) {
            throw new InvalidArgumentException('Each point must be a Point or an array with "id" and "vector".');
        }
        $payload = $point['payload'] ?? [];
        if (!is_array($payload)) {
            throw new InvalidArgumentException('A point\'s payload must be an array.');
        }

        return new Point($point['id'], $point['vector'], $payload);
    }

    private static function document(mixed $document): Document
    {
        if ($document instanceof Document) {
            return $document;
        }
        if (!is_array($document) || !isset($document['id'], $document['text']) || !is_string($document['text'])) {
            throw new InvalidArgumentException('Each document must be a Document or an array with "id" and "text".');
        }

        return new Document($document['id'], $document['text'], (array) ($document['payload'] ?? []));
    }

    private static function name(string $collection): string
    {
        if (!preg_match(self::COLLECTION, $collection)) {
            throw new InvalidArgumentException(
                '"' . $collection . '" is not a valid collection name: use 1 to 63 lower case letters, digits, underscores and hyphens, starting with a letter or digit.'
            );
        }

        return $collection;
    }
}
