// modified: 2026-02-26

import js from '@eslint/js';
import pluginSecurity from 'eslint-plugin-security';
import pluginUnicorn from 'eslint-plugin-unicorn';
import pluginJsxA11y from 'eslint-plugin-jsx-a11y';
import globals from 'globals';
import eslintConfigPrettier from 'eslint-config-prettier';

// 1. Import your modularized configs
import yamlConfig from './eslint.yaml.mjs';

// ------------------------------------------------------------------
// Global ignore patterns (replaces .eslintignore)
// ------------------------------------------------------------------
const ignorePatterns =[
    'dist/**', 'node_modules/**', 'coverage/**', '*.config.*',
    'playwright-report/**', 'test-results/**', 'build/**', '.cache/**',
    '.next/**', 'composer.lock', 'vendor/**', '.eslintcache', '.grunt/**',
    '.husky/_/**', '*.min.*', '.node_repl_history', '.npm/**',
    'npm-debug.log*', 'package-lock.json', '.phpunit.result.cache',
    '*.pyc', '__pycache__/**', '*.pyo', '.env.local.php',
    'parameters.yml', 'var/**',
];

// ------------------------------------------------------------------
// Export the flat config
// ------------------------------------------------------------------
export default[
    // Global ignores must be isolated in their own config object
    { ignores: ignorePatterns },

    // Base JavaScript
    js.configs.recommended,

    // Global language options and standard JS rules
    {
        languageOptions: {
            globals: {
                ...globals.browser,
                ...globals.node,
                ...globals.es2023,
            },
        },
        rules: {
            'no-console': ['warn', { allow:['warn', 'error'] }],
        },
    },

    // 2. Spread the imported configurations
    ...yamlConfig,

    // Security baseline
    {
        plugins: { security: pluginSecurity },
        rules: {
            ...pluginSecurity.configs.recommended.rules,
            'security/detect-object-injection': 'off', // often noisy
        },
    },

    // Code‑quality (unicorn)
    {
        plugins: { unicorn: pluginUnicorn },
        rules: {
            'unicorn/consistent-function-scoping': 'off',
            'unicorn/no-abusive-eslint-disable': 'error',
        },
    },

    // Accessibility for Vue/JSX
    {
        files:['**/*.{vue,jsx,tsx}'],
        plugins: { 'jsx-a11y': pluginJsxA11y },
        rules: {
            ...pluginJsxA11y.configs.recommended.rules,
        },
    },

    // Prettier – must be last to override conflicting rules
    eslintConfigPrettier,
];
