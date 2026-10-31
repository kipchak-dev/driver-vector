<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Result;

/**
 * A stored point, as get() returns it.
 */
final readonly class Record
{
    /**
     * @param array<string, mixed> $payload
     * @param list<float>|null $vector Only when asked for with withVectors. For Cosine collections, some stores
     *                                 return the vector normalised to length 1.
     */
    public function __construct(
        public string $id,
        public array $payload = [],
        public ?array $vector = null,
    ) {
    }
}
