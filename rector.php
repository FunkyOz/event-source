<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples', __DIR__ . '/scripts'])
    // Keep the code compatible with the minimum supported PHP version
    ->withPhpSets(php82: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true);
