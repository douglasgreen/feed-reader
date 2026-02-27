<?php
// modified: 2026-02-26

use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/bin',
        __DIR__ . '/src',
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRules([
        // PSR-12 Base
        '@PSR12' => true,

        // Strict Type Safety
        'nullable_type_declaration_for_default_null_value' => true,
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'remove_inheritdoc' => false,
        ],

        // Architecture & Design
        'class_attributes_separation' => [
            'elements' => [
                'const' => 'one',
                'method' => 'one',
                'property' => 'one',
                'trait_import' => 'none',
                'case' => 'one',
            ],
        ],

        // Constructor Property Promotion ordering (PHP 8.2+ standards)
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
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
        // PHPDoc Standards (PSR-5/PSR-19 compliance)
        'phpdoc_align' => [
            'align' => 'left',
            'tags' => ['param', 'return', 'throws', 'type', 'var'],
        ],
        'phpdoc_order' => [
            'order' => ['param', 'return', 'throws'],
        ],
        'phpdoc_trim' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_indent' => true,
        'phpdoc_line_span' => [
            'property' => 'single',
            'const' => 'single',
            'method' => 'multi',
        ],
        'phpdoc_var_without_name' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_separation' => true,
        'phpdoc_types_order' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm' => 'none',
        ],

        // Import Organization (PSR-4 compliance)
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'single_import_per_statement' => true,
        'no_leading_import_slash' => true,

        // Modern PHP 8.2+ Syntax
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
        'no_multiple_statements_per_line' => true,
        'single_line_empty_body' => true,
        'empty_loop_body' => ['style' => 'braces'],

        // Code Quality & Safety
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof' => true,
        'single_quote' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'semicolon_after_instruction' => true,
        'return_type_declaration' => true,

        // Array Syntax
        'array_syntax' => ['syntax' => 'short'],
        'whitespace_after_comma_in_array' => true,
        'no_whitespace_before_comma_in_array' => true,
        'trim_array_spaces' => true,

        // Spacing & Formatting
        'binary_operator_spaces' => [
            'default' => 'single_space',
            'operators' => ['=>' => 'single_space'],
        ],
        'concat_space' => ['spacing' => 'one'],
        'cast_spaces' => ['space' => 'single'],
        'type_declaration_spaces' => true,
        'single_space_around_construct' => true,
        'no_extra_blank_lines' => [
            'tokens' => [
                'attribute',
                'break',
                'case',
                'continue',
                'curly_brace_block',
                'default',
                'extra',
                'parenthesis_brace_block',
                'return',
                'square_brace_block',
                'switch',
                'throw',
                'use',
            ],
        ],

        // Visibility and modifiers
        'modifier_keywords' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/var/cache/.php-cs-fixer.cache')
    ->setParallelConfig(ParallelConfigFactory::detect());
