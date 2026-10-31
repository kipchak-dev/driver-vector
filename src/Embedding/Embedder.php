<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Embedding;

/**
 * Turns text into vectors. The OpenAI driver provides one (OpenAIEmbedder); register your own in the container and
 * name it in a connection's embeddings.service setting to use another provider.
 */
interface Embedder
{
    /**
     * @param list<string> $texts
     * @return list<list<float>> One vector per text, in the same order.
     */
    public function embed(array $texts): array;
}
