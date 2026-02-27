// modified: 2026-02-26

import pluginYaml from 'eslint-plugin-yml';
import * as yamlParser from 'yaml-eslint-parser';

export default [
    ...pluginYaml.configs.recommended,
    {
        files:['**/*.{yml,yaml}'],
        languageOptions: {
            parser: yamlParser,
        },
        rules: {
            'yml/quotes': ['error', { prefer: 'single', avoidEscape: true }],
            'yml/no-empty-document': 'error',
            'yml/indent':['error', 2],
            'yml/block-mapping-question-indicator-newline': 'error',
        },
    },
];
