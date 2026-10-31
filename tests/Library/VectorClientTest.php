<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Tests\Library;

use InvalidArgumentException;
use Kipchak\Driver\Vector\Embedding\Document;
use Kipchak\Driver\Vector\Embedding\Embedder;
use Kipchak\Driver\Vector\Embedding\OpenAIEmbedder;
use Kipchak\Driver\Vector\Exception\VectorException;
use Kipchak\Driver\Vector\Library\VectorClient;
use Kipchak\Driver\Vector\Query\Distance;
use Kipchak\Driver\Vector\Query\Filter;
use Kipchak\Driver\Vector\Result\CollectionInfo;
use Kipchak\Driver\Vector\Result\Record;
use Kipchak\Driver\Vector\Store\VectorStore;
use OpenAI\Responses\Embeddings\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The client against a store that records calls: batching, ordering, validation and embeddings.
 */
final class VectorClientTest extends TestCase
{
    private RecordingStore $store;

    protected function setUp(): void
    {
        $this->store = new RecordingStore();
    }

    private function client(?Embedder $embedder = null, int $batchSize = 3, int $embeddingBatchSize = 2): VectorClient
    {
        return new VectorClient($this->store, 'default', $batchSize, $embedder, 'text', $embeddingBatchSize);
    }

    public function testUpsertSendsBatches(): void
    {
        $points = (static function (): \Generator {
            for ($i = 1; $i <= 7; $i++) {
                yield ['id' => $i, 'vector' => [$i, 0], 'payload' => ['n' => $i]];
            }
        })();

        $this->assertSame(7, $this->client()->upsert('docs', $points));
        $this->assertSame([3, 3, 1], array_map('count', array_column($this->store->calls('upsert'), 1)));
        $this->assertSame('7', $this->store->calls('upsert')[2][1][0]->id);
    }

    public function testGetReturnsTheOrderAskedForWithoutDuplicates(): void
    {
        $this->store->records = [new Record('b'), new Record('1'), new Record('a')];

        $records = $this->client()->get('docs', ['a', 1, 'missing', 'b', 'a']);

        $this->assertSame(['a', '1', 'b'], array_map(static fn (Record $r): string => $r->id, $records));
        $this->assertSame([['docs', ['a', '1', 'missing', 'b'], false]], $this->store->calls('get'));
        $this->assertSame([], $this->client()->get('docs', []));
    }

    public function testDeleteSendsBatches(): void
    {
        $this->client(batchSize: 2)->delete('docs', [1, 2, 3, 3]);

        $this->assertSame([['docs', ['1', '2']], ['docs', ['3']]], $this->store->calls('delete'));
    }

    public function testDeletingAMissingCollectionDoesNothing(): void
    {
        $this->client()->deleteCollection('docs');
        $this->assertSame([], $this->store->calls('deleteCollection'));

        $this->store->exists = true;
        $this->client()->deleteCollection('docs');
        $this->assertCount(1, $this->store->calls('deleteCollection'));
    }

    public function testEnsureCollection(): void
    {
        $this->assertTrue($this->client()->ensureCollection('docs', 3, Distance::Dot));
        $this->assertSame([['docs', 3, Distance::Dot]], $this->store->calls('createCollection'));

        $this->store->exists = true;
        $this->assertFalse($this->client()->ensureCollection('docs', 3));
        $this->assertCount(1, $this->store->calls('createCollection'));
    }

    public function testSearchPassesEverything(): void
    {
        $filter = Filter::equals('a', 1);
        $this->client()->search('docs', [1, 2], 5, $filter, 0.5, true);

        $this->assertSame([['docs', [1.0, 2.0], 5, $filter, 0.5, true]], $this->store->calls('search'));
    }

    public static function invalid(): iterable
    {
        yield 'upper case collection' => [fn (VectorClient $c) => $c->count('Docs')];
        yield 'collection with a slash' => [fn (VectorClient $c) => $c->count('a/b')];
        yield 'collection starting with a hyphen' => [fn (VectorClient $c) => $c->count('-a')];
        yield 'long collection' => [fn (VectorClient $c) => $c->count(str_repeat('a', 64))];
        yield 'zero dimensions' => [fn (VectorClient $c) => $c->createCollection('docs', 0)];
        yield 'zero limit' => [fn (VectorClient $c) => $c->search('docs', [1], 0)];
        yield 'point without vector' => [fn (VectorClient $c) => $c->upsert('docs', [['id' => 1]])];
        yield 'point payload not an array' => [fn (VectorClient $c) => $c->upsert('docs', [['id' => 1, 'vector' => [1], 'payload' => 'x']])];
        yield 'text without embeddings' => [fn (VectorClient $c) => $c->searchText('docs', 'hello')];
        yield 'document without text' => [fn (VectorClient $c) => $c->upsertTexts('docs', [['id' => 1]])];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidInput(\Closure $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $call($this->client());
    }

    public function testUpsertTextsEmbedsInBatchesAndKeepsTheText(): void
    {
        $embedder = new class implements Embedder {
            public array $batches = [];

            public function embed(array $texts): array
            {
                $this->batches[] = $texts;

                return array_map(static fn (string $text): array => [strlen($text), 1.0], $texts);
            }
        };

        $written = $this->client($embedder, batchSize: 10, embeddingBatchSize: 2)->upsertTexts('docs', [
            new Document('a', 'one', ['lang' => 'en']),
            ['id' => 'b', 'text' => 'three'],
            ['id' => 'c', 'text' => 'four', 'payload' => ['text' => 'ignored', 'x' => 1]],
        ]);

        $this->assertSame(3, $written);
        $this->assertSame([['one', 'three'], ['four']], $embedder->batches);
        $points = array_merge(...array_column($this->store->calls('upsert'), 1));
        $this->assertSame([3.0, 1.0], $points[0]->vector);
        $this->assertSame(['text' => 'one', 'lang' => 'en'], $points[0]->payload);
        $this->assertSame(['text' => 'four', 'x' => 1], $points[2]->payload, 'The document text wins over a payload "text"');
    }

    public function testSearchTextEmbedsTheQuery(): void
    {
        $embedder = new class implements Embedder {
            public function embed(array $texts): array
            {
                return [[0.1, 0.2]];
            }
        };

        $this->client($embedder)->searchText('docs', 'question', 3);

        $this->assertSame([0.1, 0.2], $this->store->calls('search')[0][1]);
    }

    public function testOpenAIEmbedder(): void
    {
        $client = new ClientFake([
            CreateResponse::fake([
                'data' => [
                    ['object' => 'embedding', 'index' => 1, 'embedding' => [0.3, 0.4]],
                    ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2]],
                ],
            ]),
        ]);

        $vectors = (new OpenAIEmbedder($client, 'text-embedding-3-small', 256))->embed(['first', 'second']);

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $vectors, 'Ordered by index, not by response order');
        $client->assertSent(\OpenAI\Resources\Embeddings::class, fn (string $method, array $parameters): bool => $method === 'create'
            && $parameters === ['model' => 'text-embedding-3-small', 'input' => ['first', 'second'], 'dimensions' => 256]);
    }

    public function testOpenAIEmbedderFailuresAreVectorExceptions(): void
    {
        $client = new ClientFake([new \RuntimeException('rate limited')]);

        $this->expectException(VectorException::class);
        $this->expectExceptionMessage('rate limited');
        (new OpenAIEmbedder($client, 'text-embedding-3-small'))->embed(['x']);
    }
}

/**
 * A VectorStore that records each call and answers from public properties.
 */
final class RecordingStore implements VectorStore
{
    public bool $exists = false;

    /** @var list<Record> */
    public array $records = [];

    /** @var list<array{0: string, 1: array}> */
    private array $calls = [];

    public function calls(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->calls, static fn (array $call): bool => $call[0] === $method),
        ));
    }

    public function createCollection(string $collection, int $dimensions, Distance $distance): void
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];
    }

    public function collectionExists(string $collection): bool
    {
        return $this->exists;
    }

    public function deleteCollection(string $collection): void
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];
    }

    public function info(string $collection): CollectionInfo
    {
        return new CollectionInfo($collection, 2, Distance::Cosine, 0);
    }

    public function upsert(string $collection, array $points): void
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];
    }

    public function search(string $collection, array $vector, int $limit, ?Filter $filter, ?float $threshold, bool $withVectors): array
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return [];
    }

    public function get(string $collection, array $ids, bool $withVectors): array
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return $this->records;
    }

    public function delete(string $collection, array $ids): void
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];
    }

    public function deleteWhere(string $collection, Filter $filter): void
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];
    }

    public function count(string $collection, ?Filter $filter): int
    {
        return 0;
    }

    public function health(): bool
    {
        return true;
    }
}
