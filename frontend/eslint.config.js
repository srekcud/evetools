import js from '@eslint/js'
import globals from 'globals'
import pluginVue from 'eslint-plugin-vue'
import tseslint from 'typescript-eslint'
import eslintConfigPrettier from 'eslint-config-prettier'

export default tseslint.config(
  {
    ignores: ['dist/', 'node_modules/'],
  },

  js.configs.recommended,

  ...tseslint.configs.recommended,

  ...pluginVue.configs['flat/essential'],

  // Browser globals for all source files
  {
    languageOptions: {
      globals: {
        ...globals.browser,
      },
    },
  },

  // Node globals for config files (vite.config.js, etc.)
  {
    files: ['*.config.{js,ts}'],
    languageOptions: {
      globals: {
        ...globals.node,
      },
    },
  },

  // Enable TypeScript parser for Vue SFC <script> blocks
  {
    files: ['**/*.vue'],
    languageOptions: {
      parserOptions: {
        parser: tseslint.parser,
      },
    },
  },

  // Prettier must be last to disable conflicting formatting rules
  eslintConfigPrettier,

  // Project-specific rule overrides
  {
    rules: {
      // Relax rules that would require mass refactoring of existing code
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-unused-vars': [
        'warn',
        {
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrorsIgnorePattern: '^_',
        },
      ],
      // Allow empty catch blocks (common pattern for intentional error swallowing)
      'no-empty': ['error', { allowEmptyCatch: true }],
      // Vue-specific relaxations for existing code patterns
      'vue/multi-word-component-names': 'off',
      // Allow non-null assertions in existing code
      '@typescript-eslint/no-non-null-assertion': 'warn',
    },
  },
)
