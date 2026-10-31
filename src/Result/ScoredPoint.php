<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Result;

/**
 * A search result: a point and how close it is to the query. See Distance for what the score means.
 */
final readonly class ScoredPoint
{
    /**
     * @param array<string, mixed> $payload
     * @param list<float>|null $vector Only when asked for with withVectors. For Cosine collections, some stores
     *                                 return the vector normalised to length 1.
     */
    public function __construct(
        public string $id,
        public float $score,
        public array $payload = [],
        public ?array $vector = null,
    ) {
    }
}
