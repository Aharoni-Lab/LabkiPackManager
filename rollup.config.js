import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import terser from '@rollup/plugin-terser';
import vue from 'rollup-plugin-vue';

export default {
  input: 'resources/src/main.js',
  output: {
    file: 'resources/modules/ext.LabkiPackManager/app.bundle.js',
    format: 'iife',
    name: 'LabkiPackManager',
    sourcemap: true,
    globals: {
      'vue': 'Vue',
      '@wikimedia/codex': 'Codex'
    },
    banner: 'var Vue = (typeof mw!=="undefined"&&mw.loader&&mw.loader.require)?mw.loader.require("vue"):window.Vue;\nvar Codex = (typeof mw!=="undefined"&&mw.loader&&mw.loader.require)?mw.loader.require("@wikimedia/codex"):window.Codex;'
  },
  external: ['vue', '@wikimedia/codex'],
  plugins: [vue(), resolve(), commonjs(), terser.default ? terser.default() : terser()]
};
