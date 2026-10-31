<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Query;

use InvalidArgumentException;

/**
 * A condition on points' payloads, translated by each store into its own filter language:
 *
 *   Filter::and(
 *       Filter::equals('tenant', $tenantId),
 *       Filter::in('language', ['en', 'fr']),
 *       Filter::range('published', gte: 1704067200),
 *       Filter::not(Filter::equals('archived', true)),
 *   )
 *
 * Fields are payload keys; reach into nested objects with dots ("author.id"). equals() and in() also match an
 * array that contains the value. range() compares numbers only; store dates as Unix timestamps to filter on them.
 */
final readonly class Filter
{
    public const string EQUALS = 'equals';
    public const string IN = 'in';
    public const string RANGE = 'range';
    public const string AND = 'and';
    public const string OR = 'or';
    public const string NOT = 'not';

    private const string FIELD = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    /**
     * @param list<string|int|float|bool> $values For equals (one value) and in.
     * @param array{gt?: int|float, gte?: int|float, lt?: int|float, lte?: int|float} $bounds For range.
     * @param list<Filter> $filters For and, or and not (one filter).
     */
    private function __construct(
        public string $type,
        public ?string $field = null,
        public array $values = [],
        public array $bounds = [],
        public array $filters = [],
    ) {
    }

    /**
     * The field equals the value, or is an array containing it.
     */
    public static function equals(string $field, string|int|float|bool $value): self
    {
        return new self(self::EQUALS, self::field($field), [$value]);
    }

    /**
     * The field equals one of the values, or is an array containing one of them.
     *
     * @param list<string|int|float|bool> $values
     */
    public static function in(string $field, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('Filter::in("' . $field . '") needs at least one value.');
        }
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException('Filter::in("' . $field . '") values must be strings, numbers or booleans.');
            }
        }

        return new self(self::IN, self::field($field), array_values($values));
    }

    /**
     * The field is a number within the bounds: Filter::range('price', gte: 10, lt: 20).
     */
    public static function range(string $field, int|float|null $gt = null, int|float|null $gte = null, int|float|null $lt = null, int|float|null $lte = null): self
    {
        $bounds = array_filter(['gt' => $gt, 'gte' => $gte, 'lt' => $lt, 'lte' => $lte], static fn ($bound): bool => $bound !== null);
        if ($bounds === []) {
            throw new InvalidArgumentException('Filter::range("' . $field . '") needs at least one of gt, gte, lt or lte.');
        }

        return new self(self::RANGE, self::field($field), bounds: $bounds);
    }

    /**
     * Every filter matches.
     */
    public static function and(self ...$filters): self
    {
        return self::group(self::AND, $filters);
    }

    /**
     * At least one filter matches.
     */
    public static function or(self ...$filters): self
    {
        return self::group(self::OR, $filters);
    }

    /**
     * The filter does not match. A point without the field does not match the filter, so it matches its negation.
     */
    public static function not(self $filter): self
    {
        return new self(self::NOT, filters: [$filter]);
    }

    /**
     * The field as a list of keys: "author.id" → ['author', 'id'].
     *
     * @return list<string>
     */
    public function path(): array
    {
        return explode('.', (string) $this->field);
    }

    /**
     * @param list<Filter> $filters
     */
    private static function group(string $type, array $filters): self
    {
        if ($filters === []) {
            throw new InvalidArgumentException('Filter::' . $type . '() needs at least one filter.');
        }

        return new self($type, filters: array_values($filters));
    }

    private static function field(string $field): string
    {
        if (!preg_match(self::FIELD, $field)) {
            throw new InvalidArgumentException('"' . $field . '" is not a payload field: use letters, digits and underscores, with dots for nested fields.');
        }

        return $field;
    }
}
