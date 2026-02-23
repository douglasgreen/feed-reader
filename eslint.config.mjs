// version: 1.1.0
// modified: 2026-02-22

import js from '@eslint/js';
import pluginSecurity from 'eslint-plugin-security';
import pluginUnicorn from 'eslint-plugin-unicorn';
import pluginJsxA11y from 'eslint-plugin-jsx-a11y';
import globals from 'globals';
import eslintConfigPrettier from 'eslint-config-prettier';

export default tseslint.config(
    // Base JavaScript
    js.configs.recommended,

    // Global settings & Parsers
    {
        languageOptions: {
            globals: {
                ...globals.browser,
                ...globals.node,
                ...globals.es2023,
            },
        },
    },

    // Security baseline
    {
        plugins: { security: pluginSecurity },
        rules: {
            ...pluginSecurity.configs.recommended.rules, // Use recommended as base
            'security/detect-object-injection': 'off', // Often too noisy
        },
    },

    // Code quality
    {
        plugins: { unicorn: pluginUnicorn },
        rules: {
            'unicorn/consistent-function-scoping': 'warn',
            'unicorn/no-abusive-eslint-disable': 'error',
        },
    },

    // Ignores
    {
        ignores: [
            'dist/**',
            'node_modules/**',
            'coverage/**',
            '*.config.*',
            'playwright-report/**',
            'test-results/**',
        ],
    },

    // PRETTIER - This MUST be last to override conflicting rules
    eslintConfigPrettier,
);
