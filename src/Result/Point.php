<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Result;

use InvalidArgumentException;

/**
 * A vector with an ID and a payload of JSON-compatible data to filter on and return with matches.
 *
 * IDs are strings or non-negative integers, and come back from the store as strings.
 */
final readonly class Point
{
    public string $id;

    /** @var list<float> */
    public array $vector;

    /**
     * @param list<int|float> $vector
     * @param array<string, mixed> $payload
     */
    public function __construct(string|int $id, array $vector, public array $payload = [])
    {
        $this->id = self::id($id);
        $this->vector = self::vector($vector);
    }

    public static function id(string|int $id): string
    {
        if (is_int($id) && $id < 0) {
            throw new InvalidArgumentException('Point IDs must be strings or non-negative integers; got ' . $id . '.');
        }
        $id = (string) $id;
        if ($id === '' || strlen($id) > 512) {
            throw new InvalidArgumentException('Point IDs must be 1 to 512 bytes long.');
        }

        return $id;
    }

    /**
     * @param array<mixed> $vector
     * @return list<float>
     */
    public static function vector(array $vector): array
    {
        if ($vector === [] || !array_is_list($vector)) {
            throw new InvalidArgumentException('A vector must be a non-empty list of numbers.');
        }

        return array_map(static function (mixed $value): float {
            if ((!is_int($value) && !is_float($value)) || is_nan((float) $value) || is_infinite((float) $value)) {
                throw new InvalidArgumentException('A vector must contain only finite numbers.');
            }

            return (float) $value;
        }, $vector);
    }
}
