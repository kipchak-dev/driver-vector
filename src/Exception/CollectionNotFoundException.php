<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Exception;

final class CollectionNotFoundException extends VectorException
{
    public static function named(string $collection, ?\Throwable $previous = null): self
    {
        return new self('The vector collection "' . $collection . '" does not exist.', 404, 'not_found', $previous);
    }
}
