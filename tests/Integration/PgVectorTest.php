<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Tests\Integration;

use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kipchak\Driver\Vector\Exception\VectorConnectionException;
use Kipchak\Driver\Vector\Library\ClientFactory;
use Kipchak\Driver\Vector\Library\VectorClient;
use Kipchak\Driver\Vector\Query\Distance;
use Kipchak\Driver\Vector\Query\Filter;

final class PgVectorTest extends StoreContractTestCase
{
    private static ?Connection $connection = null;

    private static function connection(int $port = 55432): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => '127.0.0.1',
            'port' => $port,
            'dbname' => 'vectors',
            'user' => 'kipchak',
            'password' => 'kipchak-password',
        ]);
    }

    protected static function available(): bool
    {
        if (!extension_loaded('pdo_pgsql')) {
            return false;
        }
        try {
            self::$connection ??= self::connection();
            self::$connection->fetchOne('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function client(array $overrides = []): VectorClient
    {
        $container = new Container();
        $container->set('drivers.database.doctrine.dbal.primary', self::$connection ?? self::connection());

        return (new ClientFactory($container))->create('pgvector', $overrides + ['store' => 'pgvector']);
    }

    public function testTablesAndIndexes(): void
    {
        $this->client()->createCollection(self::COLLECTION, 3, Distance::Dot);

        $indexes = self::$connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE tablename = 'vector_kipchak-contract' ORDER BY indexname");
        $this->assertCount(3, $indexes);
        $this->assertStringContainsString('USING hnsw (embedding vector_ip_ops)', implode("\n", $indexes));
        $this->assertStringContainsString('USING gin (payload jsonb_path_ops)', implode("\n", $indexes));
    }

    public function testLargeVectorsAreIndexedAsHalfvec(): void
    {
        $client = $this->client();
        $client->createCollection(self::COLLECTION, 3072);
        $vector = static fn (int $hot): array => array_map(static fn (int $i): float => $i === $hot ? 1.0 : 0.001, range(0, 3071));
        $client->upsert(self::COLLECTION, [['id' => 'a', 'vector' => $vector(0)], ['id' => 'b', 'vector' => $vector(1)]]);

        $this->assertSame(['b', 'a'], self::ids($client->search(self::COLLECTION, $vector(1), 2)));
        $indexes = implode("\n", self::$connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE tablename = 'vector_kipchak-contract'"));
        $this->assertStringContainsString('halfvec_cosine_ops', $indexes);

        $plan = implode("\n", self::$connection->fetchFirstColumn(
            'EXPLAIN SELECT id FROM "vector_kipchak-contract" ORDER BY (embedding::halfvec(3072)) <=> ?::halfvec(3072) LIMIT 2',
            ['[' . implode(',', $vector(1)) . ']'],
        ));
        $this->assertStringContainsString('vector_kipchak-contract_embedding', $plan, 'The search uses the HNSW index');
    }

    public function testSelectiveFiltersStillFillTheLimit(): void
    {
        $client = $this->client(['batch_size' => 500]);
        $client->createCollection(self::COLLECTION, 8);
        $client->upsert(self::COLLECTION, (static function (): \Generator {
            mt_srand(42);
            for ($i = 0; $i < 3000; $i++) {
                yield ['id' => $i, 'vector' => array_map(static fn (): float => mt_rand() / mt_getrandmax(), range(1, 8)), 'payload' => ['rare' => $i % 300 === 0]];
            }
        })());
        self::$connection->executeStatement('ANALYZE "vector_kipchak-contract"');

        $matches = $client->search(self::COLLECTION, array_fill(0, 8, 0.5), 10, Filter::equals('rare', true));

        $this->assertCount(10, $matches, 'Iterative index scans keep reading until 10 rare points are found');
        $scores = array_map(static fn ($m): float => $m->score, $matches);
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, 'Closest first, after the relaxed-order scan');
    }

    public function testSurvivesARestart(): void
    {
        foreach (['unfiltered' => null, 'filtered, in a transaction' => Filter::equals('tenant', 'acme')] as $case => $filter) {
            $client = $this->seed();
            $this->assertNotEmpty($client->search(self::COLLECTION, [1, 0, 0], 2, $filter));

            exec('docker compose -f ' . escapeshellarg(__DIR__ . '/compose.yaml') . ' restart pgvector 2>&1', $output, $status);
            $this->assertSame(0, $status, implode("\n", $output));
            $this->waitForPostgres();

            $this->assertSame(['1', 'doc-2'], self::ids($client->search(self::COLLECTION, [1, 0, 0], 2, $filter)), $case . ': the same client works after PostgreSQL restarts');
            $client->deleteCollection(self::COLLECTION);
        }
    }

    private function waitForPostgres(): void
    {
        for ($i = 0; $i < 60; $i++) {
            try {
                self::connection()->fetchOne('SELECT 1');

                return;
            } catch (\Throwable) {
                usleep(500000);
            }
        }
        $this->fail('PostgreSQL did not come back');
    }

    public function testDoctrineConnectionIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kipchak/driver-doctrine');
        (new ClientFactory(new Container()))->create('pgvector', ['store' => 'pgvector']);
    }

    public function testUnreachable(): void
    {
        $container = new Container();
        $container->set('drivers.database.doctrine.dbal.primary', self::connection(1));

        $this->expectException(VectorConnectionException::class);
        (new ClientFactory($container))->create('pgvector', ['store' => 'pgvector'])->count(self::COLLECTION);
    }
}
