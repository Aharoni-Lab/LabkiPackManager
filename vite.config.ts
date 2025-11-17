import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), vueDevTools()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/src', import.meta.url)),
    },
  },
  build: {
    rollupOptions: {
      input: '/resources/src/main.ts',
      output: {
        dir: 'resources/modules/ext.LabkiPackManager/',
        entryFileNames: "app.bundle.js",
        format: 'iife',
        name: 'LabkiPackManager',
      },
    },
    sourcemap: true
  },
});
