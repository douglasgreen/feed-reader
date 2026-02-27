<?php

// modified: 2026-02-26

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\ClassMethod\NewInInitializerRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/bin',
        __DIR__ . '/src',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withComposerBased(
        symfony: true,
    )
    ->withSkip([
        // Empty is OK.
        DisallowedEmptyRuleFixerRector::class,

        // This rule always injects Session into AppContainer, breaking unit tests.
        NewInInitializerRector::class => [
            __DIR__ . '/src/AppContainer.php',
        ],
    ])
    ->withRules([
        DeclareStrictTypesRector::class,
    ])
    ->withImportNames(removeUnusedImports: true)
    ->withPhpVersion(PhpVersion::PHP_83);
