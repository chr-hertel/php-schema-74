<?php

declare(strict_types=1);

use Mcp\Downgrade\Rector\DowngradeEnumCaseFetchRector;
use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

/*
 * Pass 1 — every reference to a case, while the enums are still on disk as
 * enums. Rector writes each file as it finishes it, so rewriting the enum
 * declarations in the same run would leave later files reflecting plain classes.
 */
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/../../build/stage'])
    ->withAutoloadPaths([__DIR__ . '/../../build/stage'])
    ->withPhpVersion(PhpVersion::PHP_81)
    ->withRules([DowngradeEnumCaseFetchRector::class])
    ->withImportNames(false, false, false, false)
    ->withoutParallel();
