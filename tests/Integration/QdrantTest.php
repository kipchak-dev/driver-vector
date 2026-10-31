<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Tests\Integration;

use DI\Container;
use Kipchak\Driver\Vector\Exception\VectorConnectionException;
use Kipchak\Driver\Vector\Exception\VectorException;
use Kipchak\Driver\Vector\Library\ClientFactory;
use Kipchak\Driver\Vector\Library\VectorClient;
use Kipchak\Driver\Vector\Result\Point;
use Kipchak\Driver\Vector\Store\QdrantStore;

final class QdrantTest extends StoreContractTestCase
{
    private const string URL = 'http://127.0.0.1:56333';
    private const string KEY = 'kipchak-qdrant-key';

    protected static function available(): bool
    {
        $context = stream_context_create(['http' => ['header' => 'api-key: ' . self::KEY, 'timeout' => 2]]);

        return @file_get_contents(self::URL . '/collections', false, $context) !== false;
    }

    protected function client(array $overrides = []): VectorClient
    {
        return (new ClientFactory(new Container()))->create('qdrant', $overrides + [
            'store' => 'qdrant',
            'url' => self::URL,
            'api_key' => self::KEY,
        ]);
    }

    public function testIdsQdrantCannotStoreAreMappedAndKept(): void
    {
        $client = $this->seed();

        $this->assertSame(1, QdrantStore::pointId('1'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) QdrantStore::pointId('doc-2'));
        $this->assertSame(QdrantStore::pointId('doc-2'), QdrantStore::pointId('doc-2'), 'The same ID always maps to the same UUID');
        $this->assertNotSame(QdrantStore::pointId('007'), 7, 'Leading zeros keep a string ID distinct from the number');

        $this->assertArrayNotHasKey(QdrantStore::ID_KEY, $client->find(self::COLLECTION, 'doc-2')?->payload ?? []);
    }

    public function testTheIdKeyIsReserved(): void
    {
        $client = $this->seed();

        $this->expectException(\InvalidArgumentException::class);
        $client->upsert(self::COLLECTION, [new Point('x', [1, 0, 0], [QdrantStore::ID_KEY => 'y'])]);
    }

    public function testWrongApiKey(): void
    {
        try {
            $this->client(['api_key' => 'wrong'])->count(self::COLLECTION);
            $this->fail('Expected an exception');
        } catch (VectorException $exception) {
            $this->assertNotInstanceOf(VectorConnectionException::class, $exception);
            $this->assertContains($exception->status, [401, 403]);
        }
        $this->assertFalse($this->client(['api_key' => 'wrong'])->health());
    }

    public function testUnreachable(): void
    {
        $this->expectException(VectorConnectionException::class);
        $this->client(['url' => 'http://127.0.0.1:1', 'retries' => 0])->count(self::COLLECTION);
    }
}
