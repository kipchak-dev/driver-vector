<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Result;

use Kipchak\Driver\Vector\Query\Distance;

final readonly class CollectionInfo
{
    public function __construct(
        public string $name,
        public int $dimensions,
        public Distance $distance,
        public int $count,
    ) {
    }
}
