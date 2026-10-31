<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Tests;

use DI\Container;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Vector\Embedding\Embedder;
use Kipchak\Driver\Vector\Embedding\OpenAIEmbedder;
use Kipchak\Driver\Vector\Library\ClientFactory;
use Kipchak\Driver\Vector\Library\VectorClient;
use Kipchak\Driver\Vector\Store\OpenSearchStore;
use Kipchak\Driver\Vector\Store\PgVectorStore;
use Kipchak\Driver\Vector\Store\QdrantStore;
use Kipchak\Driver\Vector\Vector;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VectorDriverTest extends TestCase
{
    private const array QDRANT = ['store' => 'qdrant', 'url' => 'http://qdrant:6333'];

    private function primeDriver(array $config, ?Container $container = null): Container
    {
        $container ??= new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.vector' => $config]);
        Vector::initialise($container);

        return $container;
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    public function testRegistersOneClientPerConnection(): void
    {
        $container = $this->primeDriver(['connections' => [
            'default' => self::QDRANT,
            'search' => ['store' => 'opensearch', 'url' => 'https://opensearch:9200', 'username' => 'u', 'password' => 'p'],
        ]]);

        $this->assertTrue($container->has('drivers.vector.default'));
        $this->assertInstanceOf(QdrantStore::class, Vector::get()->store());
        $this->assertInstanceOf(OpenSearchStore::class, Vector::get('search')->store());
        $this->assertSame(Vector::get(), Vector::get('default'), 'One client per connection per worker');
    }

    public function testNoConnectionsRegistersNothing(): void
    {
        $this->assertFalse($this->primeDriver([])->has('drivers.vector.default'));
    }

    public function testPgVectorUsesTheDoctrineConnection(): void
    {
        $container = new Container();
        $dbal = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => 'postgres', 'dbname' => 'x']);
        $container->set('drivers.database.doctrine.dbal.analytics', $dbal);
        $this->primeDriver(['connections' => ['default' => ['store' => 'pgvector', 'doctrine' => 'analytics', 'table_prefix' => 'emb_']]], $container);

        $store = Vector::get()->store();
        $this->assertInstanceOf(PgVectorStore::class, $store);
        $this->assertSame($dbal, $this->property($store, 'connection'));
        $this->assertSame('emb_', $this->property($store, 'tablePrefix'));
    }

    public function testEmbeddingsUseTheOpenAIDriver(): void
    {
        $container = new Container();
        $container->set('drivers.openai.embeddings', new ClientFake());
        $this->primeDriver(['connections' => ['default' => self::QDRANT + ['embeddings' => ['openai' => 'embeddings', 'model' => 'nomic-embed-text']]]], $container);

        $this->assertInstanceOf(OpenAIEmbedder::class, $this->property(Vector::get(), 'embedder'));
    }

    public function testEmbeddingsFromAContainerService(): void
    {
        $container = new Container();
        $embedder = new class implements Embedder {
            public function embed(array $texts): array
            {
                return [];
            }
        };
        $container->set('my.embedder', $embedder);
        $this->primeDriver(['connections' => ['default' => self::QDRANT + ['embeddings' => ['service' => 'my.embedder', 'text_field' => 'body']]]], $container);

        $this->assertSame($embedder, $this->property(Vector::get(), 'embedder'));
        $this->assertSame('body', $this->property(Vector::get(), 'textField'));
    }

    public function testHttpOptions(): void
    {
        $client = (new ClientFactory(new Container()))->create('s', [
            'store' => 'opensearch',
            'url' => 'https://opensearch:9200',
            'username' => 'admin',
            'password' => 'secret',
            'tls' => ['ca' => '/ca.pem', 'cert' => '/c.pem', 'key' => '/k.pem'],
            'timeout' => 3,
            'retries' => 0,
        ]);
        $http = $this->property($client->store(), 'http');

        $this->assertSame(['auth' => ['admin', 'secret'], 'verify' => '/ca.pem', 'cert' => '/c.pem', 'ssl_key' => '/k.pem'], $this->property($http, 'options'));
        $this->assertSame(3.0, $this->property($http, 'timeout'));
        $this->assertSame(0, $this->property($http, 'retries'));

        $qdrant = (new ClientFactory(new Container()))->create('q', self::QDRANT + ['api_key' => 'k']);
        $this->assertSame(['api-key' => 'k'], $this->property($this->property($qdrant->store(), 'http'), 'headers'));
    }

    public static function invalid(): iterable
    {
        yield 'no store' => [['url' => 'http://a'], 'store must be'];
        yield 'unknown store' => [['store' => 'pinecone'], 'store must be'];
        yield 'no url' => [['store' => 'qdrant'], 'url must be'];
        yield 'url not http' => [['store' => 'qdrant', 'url' => 'qdrant:6333'], 'url must be'];
        yield 'engine' => [['store' => 'opensearch', 'url' => 'http://a', 'engine' => 'nmslib'], 'engine must be'];
        yield 'refresh' => [['store' => 'opensearch', 'url' => 'http://a', 'refresh' => 'sometimes'], 'refresh must be'];
        yield 'batch size' => [self::QDRANT + ['batch_size' => 0], 'batch_size must be at least 1'];
        yield 'cert without key' => [self::QDRANT + ['tls' => ['cert' => '/c.pem']], 'tls.cert and tls.key'];
        yield 'pgvector without doctrine' => [['store' => 'pgvector'], 'kipchak/driver-doctrine'];
        yield 'embeddings without openai' => [self::QDRANT + ['embeddings' => ['model' => 'm']], 'kipchak/driver-openai'];
        yield 'embeddings not an array' => [self::QDRANT + ['embeddings' => 'openai'], 'embeddings must be an array'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidConfig(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new ClientFactory(new Container()))->create('default', $config);
    }

    public function testEmbeddingsNeedAModel(): void
    {
        $container = new Container();
        $container->set('drivers.openai.default', new ClientFake());

        $this->expectExceptionMessage('embeddings.model is required');
        (new ClientFactory($container))->create('default', self::QDRANT + ['embeddings' => ['openai' => 'default']]);
    }

    public function testSampleConfigIsValid(): void
    {
        require_once dirname((string) (new \ReflectionClass(\Kipchak\Core\Api::class))->getFileName()) . '/Functions.php';
        $this->primeDriver(require __DIR__ . '/../sample.config.php');

        $this->assertInstanceOf(VectorClient::class, Vector::get());
    }
}
