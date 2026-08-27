<?php

/*
 * Dumps the public API surface of a Mcp\Schema tree as JSON, so the port can be
 * compared against the upstream it was generated from. Run once per checkout:
 * both define the same class names, so they cannot share a process.
 *
 * Usage: php api-surface.php <autoload.php> <src/Schema>
 */

declare(strict_types=1);

require $argv[1];

$surface = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[2])) as $file) {
    if ('php' !== $file->getExtension()) {
        continue;
    }

    $source = file_get_contents($file->getPathname());
    if (!preg_match('/^namespace (.+);/m', $source, $namespace)
        || !preg_match('/^(?:final |abstract )?(?:class|interface|enum) (\w+)/m', $source, $name)) {
        continue;
    }

    $fqcn = $namespace[1] . '\\' . $name[1];
    if (!class_exists($fqcn) && !interface_exists($fqcn)) {
        continue;
    }

    $reflection = new ReflectionClass($fqcn);

    $methods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        // Inherited members belong to the surface of the class that declares them.
        if ($method->getDeclaringClass()->getName() !== $fqcn) {
            continue;
        }
        $methods[$method->getName()] = ($method->isStatic() ? 'static ' : '')
            . $method->getNumberOfRequiredParameters() . '/' . $method->getNumberOfParameters();
    }
    ksort($methods);

    $constants = [];
    foreach ($reflection->getReflectionConstants() as $constant) {
        if (!$constant->isPublic()) {
            continue;
        }
        $value = $constant->getValue();
        // An enum-valued constant reaches PHP 7.4 as its backing value; compare
        // the two on the value, which is what both sides serialise.
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        $constants[$constant->getName()] = json_encode($value);
    }
    ksort($constants);

    $surface[$fqcn] = ['methods' => $methods, 'constants' => $constants];
}

ksort($surface);

echo json_encode($surface, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), \PHP_EOL;
