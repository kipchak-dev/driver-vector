<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Tests\Query;

use InvalidArgumentException;
use Kipchak\Driver\Vector\Query\Distance;
use Kipchak\Driver\Vector\Query\Filter;
use Kipchak\Driver\Vector\Result\Point;
use Kipchak\Driver\Vector\Store\OpenSearchStore;
use Kipchak\Driver\Vector\Store\PgVectorStore;
use Kipchak\Driver\Vector\Store\QdrantStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    private static function sample(): Filter
    {
        return Filter::and(
            Filter::equals('tenant', 'acme'),
            Filter::in('author.id', [7, 8]),
            Filter::range('price', gte: 10, lt: 20.5),
            Filter::not(Filter::or(Filter::equals('archived', true), Filter::equals('score', 0.5))),
        );
    }

    public function testQdrantFilter(): void
    {
        $this->assertSame(['must' => [
            ['must' => [['key' => 'tenant', 'match' => ['value' => 'acme']]]],
            ['must' => [['should' => [
                ['key' => 'author.id', 'match' => ['value' => 7]],
                ['key' => 'author.id', 'match' => ['value' => 8]],
            ]]]],
            ['must' => [['key' => 'price', 'range' => ['gte' => 10, 'lt' => 20.5]]]],
            ['must_not' => [['should' => [
                ['must' => [['key' => 'archived', 'match' => ['value' => true]]]],
                ['must' => [['key' => 'score', 'range' => ['gte' => 0.5, 'lte' => 0.5]]]],
            ]]]],
        ]], QdrantStore::filter(self::sample()));
    }

    public function testOpenSearchFilter(): void
    {
        $this->assertSame(['bool' => ['filter' => [
            ['term' => ['payload.tenant' => 'acme']],
            ['terms' => ['payload.author.id' => [7, 8]]],
            ['range' => ['payload.price' => ['gte' => 10, 'lt' => 20.5]]],
            ['bool' => ['must_not' => [['bool' => ['should' => [
                ['term' => ['payload.archived' => true]],
                ['term' => ['payload.score' => 0.5]],
            ], 'minimum_should_match' => 1]]]]],
        ]]], OpenSearchStore::filter(self::sample()));
    }

    public function testPgVectorFilterBindsEveryValue(): void
    {
        $params = [];
        $sql = PgVectorStore::filter(self::sample(), $params);

        $this->assertStringNotContainsString('acme', $sql);
        $this->assertSame(
            "((payload @> ?::jsonb OR payload @> ?::jsonb) AND (payload @> ?::jsonb OR payload @> ?::jsonb OR payload @> ?::jsonb OR payload @> ?::jsonb)"
            . " AND ((CASE WHEN jsonb_typeof(payload #> ?::text[]) = 'number' THEN (payload #>> ?::text[])::numeric END) >= ?"
            . " AND (CASE WHEN jsonb_typeof(payload #> ?::text[]) = 'number' THEN (payload #>> ?::text[])::numeric END) < ?)"
            . " AND (NOT COALESCE(((payload @> ?::jsonb OR payload @> ?::jsonb) OR (payload @> ?::jsonb OR payload @> ?::jsonb)), false)))",
            $sql,
        );
        $this->assertSame([
            '{"tenant":"acme"}', '{"tenant":["acme"]}',
            '{"author":{"id":7}}', '{"author":{"id":[7]}}', '{"author":{"id":8}}', '{"author":{"id":[8]}}',
            '{price}', '{price}', 10, '{price}', '{price}', 20.5,
            '{"archived":true}', '{"archived":[true]}', '{"score":0.5}', '{"score":[0.5]}',
        ], $params);
    }

    public static function badFilters(): iterable
    {
        yield 'field with a quote' => [fn () => Filter::equals("a'b", 1)];
        yield 'field with spaces' => [fn () => Filter::equals('a b', 1)];
        yield 'empty segment' => [fn () => Filter::equals('a..b', 1)];
        yield 'empty in' => [fn () => Filter::in('a', [])];
        yield 'non-scalar in' => [fn () => Filter::in('a', [[1]])];
        yield 'empty range' => [fn () => Filter::range('a')];
        yield 'empty and' => [fn () => Filter::and()];
    }

    #[DataProvider('badFilters')]
    public function testRejectsBadFilters(\Closure $make): void
    {
        $this->expectException(InvalidArgumentException::class);
        $make();
    }

    public function testDistances(): void
    {
        $this->assertTrue(Distance::Cosine->passes(0.8, 0.7));
        $this->assertFalse(Distance::Dot->passes(0.6, 0.7));
        $this->assertTrue(Distance::Euclidean->passes(0.2, 0.5));
        $this->assertFalse(Distance::Euclidean->passes(0.6, 0.5));
    }

    public function testOpenSearchScoresAreConvertedBack(): void
    {
        $this->assertEqualsWithDelta(0.6, OpenSearchStore::score(Distance::Cosine, (1 + 0.6) / 2), 1e-9);
        $this->assertEqualsWithDelta(-0.2, OpenSearchStore::score(Distance::Cosine, (1 - 0.2) / 2), 1e-9);
        $this->assertEqualsWithDelta(1.5, OpenSearchStore::score(Distance::Euclidean, 1 / (1 + 1.5 ** 2)), 1e-9);
        $this->assertEqualsWithDelta(3.0, OpenSearchStore::score(Distance::Dot, 3.0 + 1), 1e-9);
        $this->assertEqualsWithDelta(-2.0, OpenSearchStore::score(Distance::Dot, 1 / (1 + 2.0)), 1e-9);
    }

    public function testPgVectorScoresAndLiterals(): void
    {
        $this->assertEqualsWithDelta(0.75, PgVectorStore::score(Distance::Cosine, 0.25), 1e-9);
        $this->assertSame(2.5, PgVectorStore::score(Distance::Dot, -2.5));
        $this->assertSame(1.5, PgVectorStore::score(Distance::Euclidean, 1.5));
        $this->assertSame('[1.0,-0.5,0.1]', PgVectorStore::vectorLiteral([1.0, -0.5, 0.1]));
        $this->assertSame([1.0, -0.5, 0.1], PgVectorStore::parseVector('[1,-0.5,0.1]'));
    }

    public function testQdrantIds(): void
    {
        $this->assertSame(0, QdrantStore::pointId('0'));
        $this->assertSame(123456789012345678, QdrantStore::pointId('123456789012345678'));
        $this->assertIsString(QdrantStore::pointId('1234567890123456789'), 'Too large for every platform: hashed');
        $this->assertIsString(QdrantStore::pointId('007'));
        $this->assertNotSame(QdrantStore::pointId('a'), QdrantStore::pointId('b'));
    }

    public function testPoints(): void
    {
        $point = new Point(7, [1, 0.5], ['a' => 1]);
        $this->assertSame('7', $point->id);
        $this->assertSame([1.0, 0.5], $point->vector);
    }

    public static function badPoints(): iterable
    {
        yield 'negative id' => [-1, [1]];
        yield 'empty id' => ['', [1]];
        yield 'empty vector' => [1, []];
        yield 'keyed vector' => [1, ['x' => 1]];
        yield 'string in vector' => [1, ['1']];
        yield 'NaN' => [1, [NAN]];
        yield 'infinity' => [1, [INF]];
    }

    #[DataProvider('badPoints')]
    public function testRejectsBadPoints(string|int $id, array $vector): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Point($id, $vector);
    }
}
