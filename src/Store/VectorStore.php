<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Store;

use Kipchak\Driver\Vector\Query\Distance;
use Kipchak\Driver\Vector\Query\Filter;
use Kipchak\Driver\Vector\Result\CollectionInfo;
use Kipchak\Driver\Vector\Result\Point;
use Kipchak\Driver\Vector\Result\Record;
use Kipchak\Driver\Vector\Result\ScoredPoint;

/**
 * What every vector store backend provides. Collection names are already validated (lower case letters, digits,
 * underscores and hyphens) and vectors and IDs normalised by Point before they reach a store.
 */
interface VectorStore
{
    public function createCollection(string $collection, int $dimensions, Distance $distance): void;

    public function collectionExists(string $collection): bool;

    public function deleteCollection(string $collection): void;

    public function info(string $collection): CollectionInfo;

    /**
     * Inserts or replaces one batch of points.
     *
     * @param list<Point> $points
     */
    public function upsert(string $collection, array $points): void;

    /**
     * The closest points, closest first, with scores as Distance describes, leaving out those that do not pass
     * the threshold (Distance::passes()).
     *
     * @param list<float> $vector
     * @return list<ScoredPoint>
     */
    public function search(string $collection, array $vector, int $limit, ?Filter $filter, ?float $threshold, bool $withVectors): array;

    /**
     * @param list<string> $ids
     * @return list<Record> The points that exist, in no particular order.
     */
    public function get(string $collection, array $ids, bool $withVectors): array;

    /**
     * @param list<string> $ids
     */
    public function delete(string $collection, array $ids): void;

    public function deleteWhere(string $collection, Filter $filter): void;

    public function count(string $collection, ?Filter $filter): int;

    /**
     * Whether the store answers. Never throws.
     */
    public function health(): bool;
}
