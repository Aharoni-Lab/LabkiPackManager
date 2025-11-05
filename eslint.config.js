// ESLint v9 flat config
import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import eslintConfigPrettier from 'eslint-config-prettier';

export default [
  js.configs.recommended,
  // Use Vue flat config
  ...pluginVue.configs['flat/recommended'],
  {
    files: ['resources/src/**/*.{js,vue}'],
    ignores: ['resources/src/__tests__/**'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: { mw: 'readonly', require: 'readonly' } },
    rules: {
      'no-console': 'off',
      'vue/component-definition-name-casing': 'off',
      'vue/require-default-prop': 'off',
      'vue/first-attribute-linebreak': 'off',
      'no-unused-vars': 'warn', // Downgrade to warning instead of error
    }
  },
  eslintConfigPrettier
];


