<?php

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src'
    ])
    ->withPreparedSets(
        deadCode: true,
        typeDeclarations: true,
    )
    ->withComposerBased(
        symfony: true
    );
