<?php

/*
 * This file is part of the PHP 7.4 port of the official PHP MCP SDK.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Enum;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\LogicException;

/**
 * Stand-in for PHP 8.1 `enum X: string`, which the 7.4 target has no syntax for.
 *
 * Cases are memoised per class, so `===` and `match`-turned-`switch` keep the
 * identity comparison the generated code was written against. Each case is
 * reachable both as `Foo::Bar()` — the object — and as `Foo::Bar` — the backing
 * scalar, which is the only form a PHP 7.4 constant expression accepts.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
abstract class BackedEnum implements \JsonSerializable
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var string|int
     */
    public $value;

    /**
     * @var array<class-string<self>, array<string, self>>
     */
    private static $instances = [];

    /**
     * @param string|int $value
     */
    final protected function __construct(string $name, $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    /**
     * Case name to backing value, in declaration order — the order every
     * comparison against {@see self::cases()} relies on.
     *
     * @return array<string, string|int>
     */
    abstract protected static function definition(): array;

    /**
     * @return static[]
     */
    public static function cases(): array
    {
        return array_values(self::hydrate(static::class));
    }

    /**
     * @param string|int $value
     *
     * @return static
     */
    public static function from($value): self
    {
        $case = static::tryFrom($value);

        if (null === $case) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid backing value for enum %s.', \is_string($value) ? $value : var_export($value, true), static::class));
        }

        return $case;
    }

    /**
     * @param string|int $value
     *
     * @return static|null
     */
    public static function tryFrom($value): ?self
    {
        foreach (self::hydrate(static::class) as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return static
     */
    protected static function case(string $name): self
    {
        $cases = self::hydrate(static::class);

        if (!isset($cases[$name])) {
            throw new LogicException(\sprintf('Enum %s has no case "%s".', static::class, $name));
        }

        return $cases[$name];
    }

    /**
     * PHP 8.1 makes an enum uncloneable and round-trips it through serialisation
     * as the same instance. Neither is expressible here — a copy would compare
     * unequal to its own case under `===`, so both routes are closed instead of
     * quietly handing one out.
     */
    public function __clone()
    {
        throw new LogicException(\sprintf('Enum %s cannot be cloned; use %s::from($value) to reach a case.', static::class, static::class));
    }

    public function __wakeup()
    {
        throw new LogicException(\sprintf('Enum %s cannot be unserialised; store the backing value and use %s::from($value).', static::class, static::class));
    }

    /**
     * @return string|int
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->value;
    }

    /**
     * @param class-string<self> $class
     *
     * @return array<string, self>
     */
    private static function hydrate(string $class): array
    {
        if (!isset(self::$instances[$class])) {
            $cases = [];
            foreach ($class::definition() as $name => $value) {
                $cases[$name] = new $class($name, $value);
            }
            self::$instances[$class] = $cases;
        }

        return self::$instances[$class];
    }
}
