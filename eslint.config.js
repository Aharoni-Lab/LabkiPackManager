// ESLint v9 flat config
import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import eslintConfigPrettier from 'eslint-config-prettier';
import tseslint from 'typescript-eslint';
import vueParser from "vue-eslint-parser";
import tsParser from "@typescript-eslint/parser"

export default [
  js.configs.recommended,
  ...tseslint.configs.recommendedTypeChecked,
  ...tseslint.configs.stylistic,
  ...pluginVue.configs['flat/recommended'],
  {
    // Global rules
    languageOptions: {
      ecmaVersion: 2022,
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
        extraFileExtensions: [".vue"]
      },
      sourceType: 'module',
      globals: { mw: 'readonly', require: 'readonly' }
    },
    rules: {
      'vue/component-definition-name-casing': 'off',
      'no-unused-vars': 'error',
      'vue/no-undef-components': "error",
      'vue/no-undef-properties': "error",
      'vue/no-unused-properties': "error",
      'vue/no-unused-refs': "error",
      'vue/no-unused-emit-declarations': "error",
    }
  },
  {
    // Vue files
    files: ["*.vue", "**/*.vue"],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        // forward things inside vue script tags to the ts parser
        parser: tsParser,
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      }
    },
    rules: {
      'no-console': 'warn',
    }
  },
  {
    // js/ts files
    files: ['**/*.{js,ts}'],
    rules: {
      'no-console': 'error',
    }
  },
  {
    // Config
    files: ['eslint.config.js'],
    ...tseslint.configs.disableTypeChecked
  },
  eslintConfigPrettier
];


