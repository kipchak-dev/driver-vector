<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Store;

use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Kipchak\Driver\Vector\Exception\CollectionNotFoundException;
use Kipchak\Driver\Vector\Exception\VectorException;
use Kipchak\Driver\Vector\Query\Distance;
use Kipchak\Driver\Vector\Query\Filter;
use Kipchak\Driver\Vector\Result\CollectionInfo;
use Kipchak\Driver\Vector\Result\Point;
use Kipchak\Driver\Vector\Result\Record;
use Kipchak\Driver\Vector\Result\ScoredPoint;

/**
 * Qdrant over its REST API (https://api.qdrant.tech).
 *
 * Qdrant only accepts unsigned integers and UUIDs as point IDs, so other IDs are mapped to a UUID derived from
 * them, and every point keeps its own ID in the payload key _kipchak_id, which is removed from what is returned.
 */
final class QdrantStore implements VectorStore
{
    public const string ID_KEY = '_kipchak_id';

    /** The UUID namespace for IDs derived from strings (a fixed, random UUID). */
    private const string NAMESPACE = 'a7f1c4e2-3b9d-5e60-8f12-6d4b0c9e7a35';

    private const array DISTANCES = [
        'cosine' => 'Cosine',
        'dot' => 'Dot',
        'euclidean' => 'Euclid',
    ];

    public function __construct(private readonly JsonHttp $http)
    {
    }

    /**
     * Builds the exception for a Qdrant error response: {"status": {"error": "..."}}.
     */
    public static function error(Response $response): VectorException
    {
        $message = $response->json('status.error') ?? $response->body();
        if ($response->status() === 404 && is_string($message) && str_contains($message, 'Collection')
            && preg_match('/Collection `([^`]+)`/', $message, $match)) {
            return CollectionNotFoundException::named($match[1]);
        }

        return new VectorException('[Qdrant] ' . (is_string($message) && $message !== '' ? $message : 'HTTP ' . $response->status()), $response->status());
    }

    public function createCollection(string $collection, int $dimensions, Distance $distance): void
    {
        $this->http->request('PUT', $this->path($collection), [
            'vectors' => ['size' => $dimensions, 'distance' => self::DISTANCES[$distance->value]],
        ]);
    }

    public function collectionExists(string $collection): bool
    {
        return (bool) ($this->http->request('GET', $this->path($collection) . '/exists')['result']['exists'] ?? false);
    }

    public function deleteCollection(string $collection): void
    {
        $this->http->request('DELETE', $this->path($collection));
    }

    public function info(string $collection): CollectionInfo
    {
        $result = $this->http->request('GET', $this->path($collection))['result'] ?? [];
        $vectors = $result['config']['params']['vectors'] ?? [];

        return new CollectionInfo(
            name: $collection,
            dimensions: (int) ($vectors['size'] ?? 0),
            distance: Distance::from((string) array_search($vectors['distance'] ?? 'Cosine', self::DISTANCES, true)),
            count: $this->count($collection, null),
        );
    }

    public function upsert(string $collection, array $points): void
    {
        $body = [];
        foreach ($points as $point) {
            if (array_key_exists(self::ID_KEY, $point->payload)) {
                throw new InvalidArgumentException('The payload key ' . self::ID_KEY . ' is reserved by the driver.');
            }
            $body[] = [
                'id' => self::pointId($point->id),
                'vector' => $point->vector,
                'payload' => (object) ([self::ID_KEY => $point->id] + $point->payload),
            ];
        }

        $this->http->request('PUT', $this->path($collection) . '/points', ['points' => $body], ['wait' => 'true']);
    }

    public function search(string $collection, array $vector, int $limit, ?Filter $filter, ?float $threshold, bool $withVectors): array
    {
        $query = ['query' => $vector, 'limit' => $limit, 'with_payload' => true, 'with_vector' => $withVectors];
        if ($filter !== null) {
            $query['filter'] = self::filter($filter);
        }
        if ($threshold !== null) {
            // Qdrant applies it as a maximum for Euclid collections and a minimum otherwise, as Distance does.
            $query['score_threshold'] = $threshold;
        }

        $points = $this->http->request('POST', $this->path($collection) . '/points/query', $query)['result']['points'] ?? [];

        return array_map(static function (array $point): ScoredPoint {
            [$id, $payload] = self::unpack($point);

            return new ScoredPoint($id, (float) $point['score'], $payload, self::vectorOf($point));
        }, $points);
    }

    public function get(string $collection, array $ids, bool $withVectors): array
    {
        $points = $this->http->request('POST', $this->path($collection) . '/points', [
            'ids' => array_map(self::pointId(...), $ids),
            'with_payload' => true,
            'with_vector' => $withVectors,
        ])['result'] ?? [];

        return array_map(static function (array $point): Record {
            [$id, $payload] = self::unpack($point);

            return new Record($id, $payload, self::vectorOf($point));
        }, $points);
    }

    public function delete(string $collection, array $ids): void
    {
        $this->http->request('POST', $this->path($collection) . '/points/delete', ['points' => array_map(self::pointId(...), $ids)], ['wait' => 'true']);
    }

    public function deleteWhere(string $collection, Filter $filter): void
    {
        $this->http->request('POST', $this->path($collection) . '/points/delete', ['filter' => self::filter($filter)], ['wait' => 'true']);
    }

    public function count(string $collection, ?Filter $filter): int
    {
        $query = ['exact' => true];
        if ($filter !== null) {
            $query['filter'] = self::filter($filter);
        }

        return (int) ($this->http->request('POST', $this->path($collection) . '/points/count', $query)['result']['count'] ?? 0);
    }

    public function health(): bool
    {
        try {
            $this->http->request('GET', '/collections');

            return true;
        } catch (VectorException) {
            return false;
        }
    }

    /**
     * Qdrant's filter for a Filter. Every condition is wrapped in a filter object so they nest freely.
     *
     * @return array<string, mixed>
     */
    public static function filter(Filter $filter): array
    {
        return match ($filter->type) {
            Filter::AND => ['must' => array_map(self::filter(...), $filter->filters)],
            Filter::OR => ['should' => array_map(self::filter(...), $filter->filters)],
            Filter::NOT => ['must_not' => [self::filter($filter->filters[0])]],
            default => ['must' => [self::condition($filter)]],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function condition(Filter $filter): array
    {
        if ($filter->type === Filter::RANGE) {
            return ['key' => $filter->field, 'range' => $filter->bounds];
        }

        // Qdrant matches strings, integers and booleans exactly; a float is matched as a one-point range.
        $conditions = array_map(static fn (string|int|float|bool $value): array => is_float($value)
            ? ['key' => $filter->field, 'range' => ['gte' => $value, 'lte' => $value]]
            : ['key' => $filter->field, 'match' => ['value' => $value]], $filter->values);

        return count($conditions) === 1 ? $conditions[0] : ['should' => $conditions];
    }

    /**
     * The ID Qdrant stores: an integer for digit strings that fit one, otherwise a UUID derived from the ID.
     */
    public static function pointId(string $id): int|string
    {
        if (ctype_digit($id) && strlen($id) <= 18 && ($id === '0' || $id[0] !== '0')) {
            return (int) $id;
        }

        return self::uuid5($id);
    }

    private static function uuid5(string $name): string
    {
        $hash = sha1((string) hex2bin(str_replace('-', '', self::NAMESPACE)) . $name);

        return sprintf(
            '%s-%s-%x-%x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12),
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>} The point's own ID and its payload without it.
     */
    private static function unpack(array $point): array
    {
        $payload = is_array($point['payload'] ?? null) ? $point['payload'] : [];
        $id = isset($payload[self::ID_KEY]) ? (string) $payload[self::ID_KEY] : (string) $point['id'];
        unset($payload[self::ID_KEY]);

        return [$id, $payload];
    }

    /**
     * @return list<float>|null
     */
    private static function vectorOf(array $point): ?array
    {
        $vector = $point['vector'] ?? null;

        return is_array($vector) && array_is_list($vector) && $vector !== [] ? array_map('floatval', $vector) : null;
    }

    private function path(string $collection): string
    {
        return '/collections/' . rawurlencode($collection);
    }
}
