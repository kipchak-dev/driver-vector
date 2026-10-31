<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Query;

/**
 * How a collection compares vectors, and what a match's score means:
 *
 *  - Cosine: the cosine similarity, from -1 to 1; higher is closer. The usual choice for text embeddings.
 *  - Dot: the dot product; higher is closer. For vectors already normalised to length 1, the same order as Cosine.
 *  - Euclidean: the straight-line distance; LOWER is closer.
 *
 * Every store reports scores this way, whatever its own scale.
 */
enum Distance: string
{
    case Cosine = 'cosine';
    case Dot = 'dot';
    case Euclidean = 'euclidean';

    /**
     * Whether a higher score is a closer match.
     */
    public function higherIsCloser(): bool
    {
        return $this !== self::Euclidean;
    }

    /**
     * Whether a score passes a threshold: at least it for Cosine and Dot, at most it for Euclidean.
     */
    public function passes(float $score, float $threshold): bool
    {
        return $this->higherIsCloser() ? $score >= $threshold : $score <= $threshold;
    }
}
