<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Tests\Integration;

use DI\Container;
use Kipchak\Driver\Vector\Exception\VectorConnectionException;
use Kipchak\Driver\Vector\Library\ClientFactory;
use Kipchak\Driver\Vector\Library\VectorClient;
use Kipchak\Driver\Vector\Query\Distance;

final class OpenSearchTest extends StoreContractTestCase
{
    private const string URL = 'http://127.0.0.1:59200';

    protected static function available(): bool
    {
        return @file_get_contents(self::URL . '/_cluster/health', false, stream_context_create(['http' => ['timeout' => 2]])) !== false;
    }

    protected function client(array $overrides = []): VectorClient
    {
        return (new ClientFactory(new Container()))->create('opensearch', $overrides + [
            'store' => 'opensearch',
            'url' => self::URL,
            'index_prefix' => 'test-',
        ]);
    }

    public function testIndexesUseThePrefix(): void
    {
        $this->client()->createCollection(self::COLLECTION, 3);

        $this->assertNotFalse(@file_get_contents(self::URL . '/test-' . self::COLLECTION));
    }

    public function testFaissEngine(): void
    {
        $client = $this->client(['engine' => 'faiss']);
        $client->createCollection(self::COLLECTION, 3, Distance::Euclidean);
        $client->upsert(self::COLLECTION, self::points());

        $this->assertSame(['1', 'doc-2'], self::ids($client->search(self::COLLECTION, [1, 0, 0], 2)));
    }

    public function testUnreachable(): void
    {
        $this->expectException(VectorConnectionException::class);
        $this->client(['url' => 'http://127.0.0.1:1', 'retries' => 0])->count(self::COLLECTION);
    }
}
