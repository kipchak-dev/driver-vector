<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Tests\Integration;

use InvalidArgumentException;
use Kipchak\Driver\Vector\Exception\CollectionNotFoundException;
use Kipchak\Driver\Vector\Exception\VectorException;
use Kipchak\Driver\Vector\Library\VectorClient;
use Kipchak\Driver\Vector\Query\Distance;
use Kipchak\Driver\Vector\Query\Filter;
use Kipchak\Driver\Vector\Result\Point;
use Kipchak\Driver\Vector\Result\ScoredPoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every store must share, run against each one in Docker (tests/Integration/start-services.sh).
 * Skipped when the store is not running.
 */
abstract class StoreContractTestCase extends TestCase
{
    protected const string COLLECTION = 'kipchak-contract';

    abstract protected function client(array $overrides = []): VectorClient;

    abstract protected static function available(): bool;

    public static function setUpBeforeClass(): void
    {
        if (!static::available()) {
            self::markTestSkipped(static::class . ': the store is not running; start it with tests/Integration/start-services.sh');
        }
    }

    protected function setUp(): void
    {
        $this->client()->deleteCollection(self::COLLECTION);
    }

    protected function tearDown(): void
    {
        $this->client()->deleteCollection(self::COLLECTION);
    }

    /**
     * Five points on the unit circle and beyond, with payloads covering every filter.
     *
     * @return list<Point>
     */
    protected static function points(): array
    {
        return [
            new Point(1, [1, 0, 0], ['tenant' => 'acme', 'year' => 2024, 'price' => 9.5, 'public' => true, 'tags' => ['news', 'tech'], 'author' => ['id' => 7]]),
            new Point('doc-2', [0.9, 0.1, 0], ['tenant' => 'acme', 'year' => 2025, 'price' => 20, 'public' => false, 'tags' => ['tech']]),
            new Point('550e8400-e29b-41d4-a716-446655440000', [0, 1, 0], ['tenant' => 'globex', 'year' => 2023, 'price' => 5, 'public' => true, 'author' => ['id' => 8]]),
            new Point('ünïcode/id #4', [0, 0, 1], ['tenant' => 'globex', 'year' => 2026, 'title' => 'Ünïcödé 日本語']),
            new Point(42, [-1, 0, 0], ['tenant' => 'initech']),
        ];
    }

    protected function seed(Distance $distance = Distance::Cosine): VectorClient
    {
        $client = $this->client();
        $client->createCollection(self::COLLECTION, 3, $distance);
        $this->assertSame(5, $client->upsert(self::COLLECTION, self::points()));

        return $client;
    }

    /**
     * @param list<ScoredPoint> $matches
     * @return list<string>
     */
    protected static function ids(array $matches): array
    {
        return array_map(static fn (object $match): string => $match->id, $matches);
    }

    public function testCollections(): void
    {
        $client = $this->client();
        $this->assertFalse($client->collectionExists(self::COLLECTION));

        $this->assertTrue($client->ensureCollection(self::COLLECTION, 3, Distance::Euclidean));
        $this->assertFalse($client->ensureCollection(self::COLLECTION, 3, Distance::Euclidean));
        $this->assertTrue($client->collectionExists(self::COLLECTION));

        $info = $client->info(self::COLLECTION);
        $this->assertSame(3, $info->dimensions);
        $this->assertSame(Distance::Euclidean, $info->distance);
        $this->assertSame(0, $info->count);

        $client->deleteCollection(self::COLLECTION);
        $this->assertFalse($client->collectionExists(self::COLLECTION));
        $client->deleteCollection(self::COLLECTION);
    }

    public function testCreatingAnExistingCollectionFails(): void
    {
        $client = $this->client();
        $client->createCollection(self::COLLECTION, 3);

        $this->expectException(VectorException::class);
        $client->createCollection(self::COLLECTION, 3);
    }

    public function testMissingCollections(): void
    {
        $client = $this->client();

        foreach ([
            'search' => fn () => $client->search('kipchak-missing', [1, 0, 0]),
            'upsert' => fn () => $client->upsert('kipchak-missing', [new Point(1, [1, 0, 0])]),
            'count' => fn () => $client->count('kipchak-missing'),
            'info' => fn () => $client->info('kipchak-missing'),
        ] as $operation => $call) {
            try {
                $call();
                $this->fail($operation . ' on a missing collection should throw');
            } catch (CollectionNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public static function distances(): iterable
    {
        // Query [1, 0.5, 0] against the points' vectors, scored as Distance describes.
        $query = [1, 0.5, 0];
        $norm = sqrt(1.25);
        yield 'cosine' => [Distance::Cosine, $query, ['doc-2' => 0.95 / ($norm * sqrt(0.82)), '1' => 1 / $norm, '550e8400-e29b-41d4-a716-446655440000' => 0.5 / $norm]];
        yield 'dot' => [Distance::Dot, $query, ['1' => 1.0, 'doc-2' => 0.95, '550e8400-e29b-41d4-a716-446655440000' => 0.5]];
        yield 'euclidean' => [Distance::Euclidean, $query, ['doc-2' => sqrt(0.01 + 0.16), '1' => 0.5, '550e8400-e29b-41d4-a716-446655440000' => sqrt(1.25)]];
    }

    /**
     * @param array<string, float> $expected The three closest IDs in order, with their scores.
     */
    #[DataProvider('distances')]
    public function testScoresAreTheSameInEveryStore(Distance $distance, array $query, array $expected): void
    {
        $client = $this->seed($distance);

        $matches = $client->search(self::COLLECTION, $query, 3);

        $this->assertSame(array_map('strval', array_keys($expected)), self::ids($matches));
        foreach ($matches as $index => $match) {
            $this->assertEqualsWithDelta(array_values($expected)[$index], $match->score, 0.001, $match->id . ' score');
        }
    }

    public function testThresholds(): void
    {
        $cosine = $this->seed(Distance::Cosine);
        $this->assertSame(['1', 'doc-2'], self::ids($cosine->search(self::COLLECTION, [1, 0, 0], threshold: 0.9)));

        $cosine->deleteCollection(self::COLLECTION);
        $euclidean = $this->seed(Distance::Euclidean);
        $this->assertSame(['1', 'doc-2'], self::ids($euclidean->search(self::COLLECTION, [1, 0, 0], threshold: 0.5)));
    }

    public function testSearchReturnsPayloadsAndVectors(): void
    {
        $client = $this->seed(Distance::Euclidean);

        $match = $client->search(self::COLLECTION, [0, 0, 1], 1)[0];
        $this->assertSame('ünïcode/id #4', $match->id);
        // Key order is not kept by every store (PostgreSQL's jsonb sorts keys).
        $this->assertEquals(['tenant' => 'globex', 'year' => 2026, 'title' => 'Ünïcödé 日本語'], $match->payload);
        $this->assertNull($match->vector);

        $withVector = $client->search(self::COLLECTION, [0, 0, 1], 1, withVectors: true)[0];
        $this->assertEqualsWithDelta([0.0, 0.0, 1.0], $withVector->vector, 0.0001);
    }

    public static function filters(): iterable
    {
        yield 'equals string' => [Filter::equals('tenant', 'acme'), ['1', 'doc-2']];
        yield 'equals integer' => [Filter::equals('year', 2023), ['550e8400-e29b-41d4-a716-446655440000']];
        yield 'equals float' => [Filter::equals('price', 9.5), ['1']];
        yield 'equals boolean' => [Filter::equals('public', false), ['doc-2']];
        yield 'equals inside an array' => [Filter::equals('tags', 'tech'), ['1', 'doc-2']];
        yield 'equals nested' => [Filter::equals('author.id', 8), ['550e8400-e29b-41d4-a716-446655440000']];
        yield 'in' => [Filter::in('tenant', ['globex', 'initech']), ['550e8400-e29b-41d4-a716-446655440000', 'ünïcode/id #4', '42']];
        yield 'in an array' => [Filter::in('tags', ['news', 'sport']), ['1']];
        yield 'range' => [Filter::range('year', gte: 2024, lt: 2026), ['1', 'doc-2']];
        yield 'range on floats' => [Filter::range('price', gt: 9, lte: 20), ['1', 'doc-2']];
        yield 'and' => [Filter::and(Filter::equals('tenant', 'acme'), Filter::equals('public', true)), ['1']];
        yield 'or' => [Filter::or(Filter::equals('year', 2026), Filter::equals('tenant', 'initech')), ['ünïcode/id #4', '42']];
        yield 'not, matching points without the field' => [Filter::not(Filter::equals('public', true)), ['doc-2', 'ünïcode/id #4', '42']];
        yield 'nested groups' => [
            Filter::and(Filter::or(Filter::equals('tenant', 'acme'), Filter::equals('tenant', 'globex')), Filter::not(Filter::range('year', lte: 2024))),
            ['doc-2', 'ünïcode/id #4'],
        ];
        yield 'nothing' => [Filter::equals('tenant', 'nobody'), []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('filters')]
    public function testFilters(Filter $filter, array $expected): void
    {
        $client = $this->seed();

        $this->assertEqualsCanonicalizing($expected, self::ids($client->search(self::COLLECTION, [1, 1, 1], 10, $filter)), 'search');
        $this->assertSame(count($expected), $client->count(self::COLLECTION, $filter), 'count');
    }

    public function testGetKeepsTheOrderAskedFor(): void
    {
        $client = $this->seed();

        $records = $client->get(self::COLLECTION, [42, 'missing', 'doc-2', 1]);
        $this->assertSame(['42', 'doc-2', '1'], self::ids($records));
        $this->assertSame(['tenant' => 'initech'], $records[0]->payload);
        $this->assertNull($records[0]->vector);

        $this->assertEqualsWithDelta([0.0, 0.0, 1.0], $client->find(self::COLLECTION, 'ünïcode/id #4', withVectors: true)?->vector, 0.0001);
        $this->assertNull($client->find(self::COLLECTION, 'missing'));
    }

    public function testUpsertReplacesAndCounts(): void
    {
        $client = $this->seed();
        $this->assertSame(5, $client->count(self::COLLECTION));

        $client->upsert(self::COLLECTION, [['id' => 'doc-2', 'vector' => [0, 0, 1], 'payload' => ['tenant' => 'replaced']]]);

        $this->assertSame(5, $client->count(self::COLLECTION));
        $this->assertSame(['tenant' => 'replaced'], $client->find(self::COLLECTION, 'doc-2')?->payload);
        $this->assertSame(5, $client->info(self::COLLECTION)->count);
    }

    public function testBatches(): void
    {
        $client = $this->client(['batch_size' => 7]);
        $client->createCollection(self::COLLECTION, 3);

        $points = (static function (): \Generator {
            for ($i = 1; $i <= 50; $i++) {
                yield ['id' => $i, 'vector' => [cos($i), sin($i), $i / 50], 'payload' => ['n' => $i]];
            }
        })();

        $this->assertSame(50, $client->upsert(self::COLLECTION, $points));
        $this->assertSame(50, $client->count(self::COLLECTION));
        $this->assertSame(10, $client->count(self::COLLECTION, Filter::range('n', gt: 40)));
    }

    public function testDelete(): void
    {
        $client = $this->seed();

        $client->delete(self::COLLECTION, [1, 'missing']);
        $this->assertSame(4, $client->count(self::COLLECTION));
        $this->assertNull($client->find(self::COLLECTION, 1));

        $client->deleteWhere(self::COLLECTION, Filter::equals('tenant', 'globex'));
        $this->assertSame(['doc-2', '42'], self::ids($client->get(self::COLLECTION, ['doc-2', '550e8400-e29b-41d4-a716-446655440000', 'ünïcode/id #4', 42])));
    }

    public function testWrongDimensionsAreRefused(): void
    {
        $client = $this->seed();

        $this->expectException(VectorException::class);
        $client->upsert(self::COLLECTION, [new Point('x', [1, 0])]);
    }

    public function testInvalidNamesNeverReachTheStore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->search('Robert"); DROP TABLE x;--', [1, 0, 0]);
    }

    public function testHealth(): void
    {
        $this->assertTrue($this->client()->health());
    }
}
