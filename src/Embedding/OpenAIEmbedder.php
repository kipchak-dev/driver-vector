<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Embedding;

use Kipchak\Driver\Vector\Exception\VectorException;

/**
 * Embeddings from a connection of the OpenAI driver: OpenAI itself, or any OpenAI-compatible server its base_uri
 * points at (Azure OpenAI, Ollama, vLLM, LocalAI, ...).
 */
final readonly class OpenAIEmbedder implements Embedder
{
    /**
     * @param object $client The OpenAI driver's client (anything with embeddings()->create()).
     * @param int|null $dimensions Shortens text-embedding-3 vectors to this many dimensions.
     */
    public function __construct(
        private object $client,
        private string $model,
        private ?int $dimensions = null,
    ) {
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $request = ['model' => $this->model, 'input' => array_values($texts)];
        if ($this->dimensions !== null) {
            $request['dimensions'] = $this->dimensions;
        }

        try {
            $response = $this->client->embeddings()->create($request);
        } catch (\Throwable $exception) {
            throw new VectorException('Embedding with ' . $this->model . ' failed: ' . $exception->getMessage(), previous: $exception);
        }

        $vectors = [];
        foreach ($response->embeddings as $embedding) {
            $vectors[$embedding->index] = array_map('floatval', $embedding->embedding);
        }
        ksort($vectors);
        if (count($vectors) !== count($texts)) {
            throw new VectorException('Embedding with ' . $this->model . ' returned ' . count($vectors) . ' vectors for ' . count($texts) . ' texts.');
        }

        return array_values($vectors);
    }
}
