<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__)
    ->exclude('vendor')
    ->exclude('node_modules')
    ->exclude('var')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRules([
        '@PSR12' => true,
        'ordered_class_elements' => [
            'order' => [
                'use_trait',

                'constant_public',
                'constant_protected',
                'constant_private',

                'property_public_static',
                'property_public',
                'property_protected_static',
                'property_protected',
                'property_private_static',
                'property_private',

                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public_static',
                'method_public',
                'method_protected_static',
                'method_protected',
                'method_private_static',
                'method_private',
            ],
            'sort_algorithm' => 'none',
        ],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/var/cache/.php-cs-fixer.cache');
