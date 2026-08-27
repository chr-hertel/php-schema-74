<?php

declare(strict_types=1);

use Mcp\Downgrade\Rector\DowngradeEnumToPolyfillClassRector;
use Mcp\Downgrade\Rector\DowngradePhpUnitAttributesRector;
use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

/*
 * Pass 2 — the enum declarations themselves, now that every reference to a case
 * has been repointed at the accessor that replaces it.
 */
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/../../build/stage'])
    ->withAutoloadPaths([__DIR__ . '/../../build/stage'])
    ->withPhpVersion(PhpVersion::PHP_81)
    ->withRules([
        DowngradeEnumToPolyfillClassRector::class,
        DowngradePhpUnitAttributesRector::class,
    ])
    ->withImportNames(false, false, false, false)
    ->withoutParallel();
