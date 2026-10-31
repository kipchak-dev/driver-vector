<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Embedding;

/**
 * A text to embed and store, for upsertTexts(). The text is stored in the payload under the connection's
 * embeddings.text_field ("text" by default), so searches can return it.
 */
final readonly class Document
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string|int $id,
        public string $text,
        public array $payload = [],
    ) {
    }
}
