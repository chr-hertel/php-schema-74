<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DowngradePhp80\Rector\Enum_\DowngradeEnumToConstantListClassRector;

/*
 * Pass 2 — the stock downgrade sets, minus the enum rule: pass 1 already turned
 * every enum into a class the constant-list rule would flatten wrongly.
 */
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/../../build/stage'])
    ->withAutoloadPaths([__DIR__ . '/../../build/stage'])
    ->withDowngradeSets(php74: true)
    ->withSkip([
        DowngradeEnumToConstantListClassRector::class,
    ])
    ->withImportNames(false, false, false, false)
    ->withoutParallel();
