<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Exception;

use RuntimeException;

/**
 * The vector store refused or failed an operation. $status is the HTTP status for Qdrant and OpenSearch, and
 * $storeError the store's own error type or SQLSTATE where it gives one.
 */
class VectorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $storeError = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
