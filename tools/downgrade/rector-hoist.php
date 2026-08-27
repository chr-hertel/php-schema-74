<?php

declare(strict_types=1);

use Mcp\Downgrade\Rector\HoistNonConstantDefaultRector;
use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

/*
 * Pass 3 — runs last, so promoted properties are already ordinary ones and the
 * hoisted assignment lands ahead of the constructor's own assignments.
 */
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/../../build/stage'])
    ->withAutoloadPaths([__DIR__ . '/../../build/stage'])
    ->withPhpVersion(PhpVersion::PHP_74)
    ->withRules([HoistNonConstantDefaultRector::class])
    ->withImportNames(false, false, false, false)
    ->withoutParallel();
