<?php

/*
 * Reports what the port lost against upstream. Enum members are exempt from the
 * losses the polyfill causes by construction: `cases`, `from` and `tryFrom` move
 * to the base class, and case accessors are additions the 8.1 side has no need
 * for.
 *
 * Usage: php compare.php <upstream.json> <port.json>
 */

declare(strict_types=1);

$upstream = json_decode(file_get_contents($argv[1]), true);
$port = json_decode(file_get_contents($argv[2]), true);

$polyfillBase = 'Mcp\Schema\Enum\BackedEnum';
$inheritedByPolyfill = ['cases', 'from', 'tryFrom', 'jsonSerialize'];

$differences = [];

foreach (array_diff(array_keys($upstream), array_keys($port)) as $lost) {
    $differences[] = "class missing from the port: $lost";
}

foreach (array_diff(array_keys($port), array_keys($upstream)) as $added) {
    if ($polyfillBase === $added) {
        continue;
    }
    $differences[] = "class in the port with no upstream counterpart: $added";
}

foreach ($upstream as $fqcn => $expected) {
    if (!isset($port[$fqcn])) {
        continue;
    }

    $actual = $port[$fqcn];
    $isPolyfilledEnum = ($port[$fqcn]['methods']['definition'] ?? null) !== null
        || [] !== array_intersect($inheritedByPolyfill, array_diff(array_keys($expected['methods']), array_keys($actual['methods'])));

    foreach ($expected['methods'] as $method => $arity) {
        if (!isset($actual['methods'][$method])) {
            if ($isPolyfilledEnum && \in_array($method, $inheritedByPolyfill, true)) {
                continue;
            }
            $differences[] = "$fqcn::$method() is missing from the port";
            continue;
        }

        if ($actual['methods'][$method] !== $arity) {
            $differences[] = "$fqcn::$method() takes $arity upstream and {$actual['methods'][$method]} in the port";
        }
    }

    foreach ($expected['constants'] as $constant => $value) {
        if (!isset($actual['constants'][$constant])) {
            $differences[] = "$fqcn::$constant is missing from the port";
            continue;
        }

        if ($actual['constants'][$constant] !== $value) {
            $differences[] = "$fqcn::$constant is $value upstream and {$actual['constants'][$constant]} in the port";
        }
    }
}

if ([] === $differences) {
    printf("API parity: %d classes, no differences\n", \count($upstream));

    exit(0);
}

echo implode("\n", $differences), "\n";
printf("error: %d differences against upstream\n", \count($differences));

exit(1);
