<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Library;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kipchak\Driver\Http\Library\Http as HttpClient;
use Kipchak\Driver\Vector\Embedding\Embedder;
use Kipchak\Driver\Vector\Embedding\OpenAIEmbedder;
use Kipchak\Driver\Vector\Store\JsonHttp;
use Kipchak\Driver\Vector\Store\OpenSearchStore;
use Kipchak\Driver\Vector\Store\PgVectorStore;
use Kipchak\Driver\Vector\Store\QdrantStore;
use Kipchak\Driver\Vector\Store\VectorStore;
use Psr\Container\ContainerInterface;

/**
 * Builds a VectorClient from one connection in kipchak.vector, using the other Kipchak drivers it names:
 * the HTTP driver for Qdrant and OpenSearch, a Doctrine driver DBAL connection for pgvector, and an OpenAI driver
 * connection (or any Embedder in the container) for embeddings.
 */
final readonly class ClientFactory
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function create(string $name, array $config): VectorClient
    {
        $embeddings = $config['embeddings'] ?? null;
        if ($embeddings !== null && !is_array($embeddings)) {
            throw $this->invalid($name, 'embeddings must be an array');
        }

        return new VectorClient(
            store: $this->store($name, $config),
            connectionName: $name,
            batchSize: $this->positive($name, $config, 'batch_size', 256),
            embedder: $embeddings === null ? null : $this->embedder($name, $embeddings),
            textField: (string) ($embeddings['text_field'] ?? 'text'),
            embeddingBatchSize: $embeddings === null ? 100 : $this->positive($name, $embeddings, 'batch_size', 100),
        );
    }

    private function store(string $name, array $config): VectorStore
    {
        return match ($config['store'] ?? null) {
            'qdrant' => new QdrantStore($this->http($name, $config, 'Qdrant', QdrantStore::error(...), self::qdrantHeaders($config))),
            'opensearch' => new OpenSearchStore(
                $this->http($name, $config, 'OpenSearch', OpenSearchStore::error(...), [], self::basicAuth($config)),
                indexPrefix: (string) ($config['index_prefix'] ?? ''),
                engine: $this->oneOf($name, $config, 'engine', ['lucene', 'faiss'], 'lucene'),
                refresh: $this->oneOf($name, $config, 'refresh', ['wait_for', 'true', 'false'], 'wait_for'),
            ),
            'pgvector' => new PgVectorStore($this->dbal($name, $config), (string) ($config['table_prefix'] ?? 'vector_')),
            default => throw $this->invalid($name, 'store must be "qdrant", "pgvector" or "opensearch"'),
        };
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    private function http(string $name, array $config, string $store, \Closure $error, array $headers, array $options = []): JsonHttp
    {
        $url = $config['url'] ?? null;
        if (!is_string($url) || !preg_match('~^https?://[^\s?#]+$~', $url)) {
            throw $this->invalid($name, 'url must be an http:// or https:// URL, such as "http://qdrant:6333"');
        }

        return new JsonHttp(
            http: new HttpClient(),
            baseUrl: $url,
            store: $store,
            error: $error,
            headers: $headers,
            options: $options + $this->tls($name, $config['tls'] ?? []),
            timeout: (float) ($config['timeout'] ?? 10.0),
            connectTimeout: (float) ($config['connect_timeout'] ?? 2.0),
            retries: (int) ($config['retries'] ?? 1),
        );
    }

    private function dbal(string $name, array $config): Connection
    {
        $connection = (string) ($config['doctrine'] ?? 'primary');
        $key = 'drivers.database.doctrine.dbal.' . $connection;
        if (!$this->container->has($key)) {
            throw $this->invalid(
                $name,
                'pgvector uses the Doctrine driver\'s DBAL connection "' . $connection . '", which is not configured. '
                . 'Install kipchak/driver-doctrine, enable the connection in kipchak.doctrine and initialise Doctrine before Vector'
            );
        }

        $dbal = $this->container->get($key);
        if (!$dbal instanceof Connection) {
            throw $this->invalid($name, '"' . $key . '" is not a Doctrine DBAL connection');
        }

        return $dbal;
    }

    private function embedder(string $name, array $embeddings): Embedder
    {
        if (isset($embeddings['service'])) {
            $service = $this->container->get((string) $embeddings['service']);
            if (!$service instanceof Embedder) {
                throw $this->invalid($name, 'embeddings.service "' . $embeddings['service'] . '" is not a ' . Embedder::class);
            }

            return $service;
        }

        $connection = (string) ($embeddings['openai'] ?? 'default');
        $key = 'drivers.openai.' . $connection;
        if (!$this->container->has($key)) {
            throw $this->invalid(
                $name,
                'embeddings use the OpenAI driver\'s connection "' . $connection . '", which is not configured. '
                . 'Install kipchak/driver-openai and initialise it before Vector, or set embeddings.service'
            );
        }
        $model = $embeddings['model'] ?? null;
        if (!is_string($model) || $model === '') {
            throw $this->invalid($name, 'embeddings.model is required, such as "text-embedding-3-small"');
        }

        return new OpenAIEmbedder(
            $this->container->get($key),
            $model,
            isset($embeddings['dimensions']) ? (int) $embeddings['dimensions'] : null,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function qdrantHeaders(array $config): array
    {
        $key = (string) ($config['api_key'] ?? '');

        return $key === '' ? [] : ['api-key' => $key];
    }

    /**
     * @return array<string, mixed>
     */
    private static function basicAuth(array $config): array
    {
        $username = (string) ($config['username'] ?? '');

        return $username === '' ? [] : ['auth' => [$username, (string) ($config['password'] ?? '')]];
    }

    /**
     * @return array<string, mixed>
     */
    private function tls(string $name, mixed $tls): array
    {
        if (!is_array($tls)) {
            throw $this->invalid($name, 'tls must be an array');
        }

        $options = [];
        if (isset($tls['ca']) && $tls['ca'] !== '') {
            $options['verify'] = (string) $tls['ca'];
        } elseif (array_key_exists('verify', $tls)) {
            $options['verify'] = (bool) $tls['verify'];
        }

        $hasCert = isset($tls['cert']) && $tls['cert'] !== '';
        $hasKey = isset($tls['key']) && $tls['key'] !== '';
        if ($hasCert !== $hasKey) {
            throw $this->invalid($name, 'client certificates need both tls.cert and tls.key');
        }
        if ($hasCert) {
            $options['cert'] = (string) $tls['cert'];
            $options['ssl_key'] = (string) $tls['key'];
        }

        return $options;
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(string $name, array $config, string $key, array $allowed, string $default): string
    {
        $value = (string) ($config[$key] ?? $default);
        if (!in_array($value, $allowed, true)) {
            throw $this->invalid($name, $key . ' must be one of "' . implode('", "', $allowed) . '"');
        }

        return $value;
    }

    private function positive(string $name, array $config, string $key, int $default): int
    {
        $value = (int) ($config[$key] ?? $default);
        if ($value < 1) {
            throw $this->invalid($name, $key . ' must be at least 1');
        }

        return $value;
    }

    private function invalid(string $name, string $problem): InvalidArgumentException
    {
        return new InvalidArgumentException('Vector connection "' . $name . '": ' . $problem . '.');
    }
}
