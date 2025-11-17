// ESLint v9 flat config
import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import eslintConfigPrettier from 'eslint-config-prettier';
import tseslint from 'typescript-eslint';
import vueParser from "vue-eslint-parser";
import tsParser from "@typescript-eslint/parser";
import globals from 'globals';

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
      globals: { mw: 'readonly', require: 'readonly', } // ...globals.browser }
    },
    rules: {
      'vue/component-definition-name-casing': 'off',
      // disable base rule, use tslint's
      'no-unused-vars': 'off',
      "@typescript-eslint/no-unused-vars": "error",
      'vue/no-undef-components': "error",
      'vue/no-undef-properties': "error",
      'vue/no-unused-properties': "error",
      'vue/no-unused-refs': "error",
      'vue/no-unused-emit-declarations': "error",
      "@typescript-eslint/consistent-indexed-object-style": "error",
      "@typescript-eslint/consistent-type-imports": "error",
      "@typescript-eslint/no-empty-object-type": [
        "error",
        {
          allowInterfaces: 'with-single-extends'
        }
        ],
      '@typescript-eslint/no-restricted-types': [
        "error",
        {
          "types": {
            "Record<string, unknown>": "Having generic record types is effectively not having types! Define the keys and values specifically." +
              "In this extension, we do in fact always know what type everything is because we do not interact with external systems.",
            "unknown": "We always know the types in this extension since we don't interact with external systems. Define what this type actually is."
          }
        }
      ]
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
      // make error after conferring with daniel -jls
      'no-console': 'warn',
    }
  },
  {
    // test-specific rule overrides
    // these should be removed once ts is more established throughout the frontend
    // for now there aren't many tests, and we don't have types for a lot of things.
    files: ['resources/src/__tests__/**/*.{js,ts}'],
    rules: {
      '@typescript-eslint/no-explicit-any': "off",
      '@typescript-eslint/no-unsafe-assignment': 'off',
      '@typescript-eslint/no-unsafe-return': "off",
      '@typescript-eslint/no-unsafe-member-access': 'off',
      '@typescript-eslint/no-unsafe-call': 'off',
    }
  },
  {
    // Config
    files: ['eslint.config.js'],
    ...tseslint.configs.disableTypeChecked
  },
  eslintConfigPrettier
];


