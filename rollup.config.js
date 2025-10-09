import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import terser from '@rollup/plugin-terser';
import vue from 'rollup-plugin-vue';
import scss from 'rollup-plugin-scss';
import css from 'rollup-plugin-css-only';
import * as sass from 'sass';

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
  plugins: [
    // Compile Vue SFCs; extract CSS to a file handled below
    vue({ css: false }),
    // Compile global SCSS to CSS file loaded by RL
    scss({ output: 'resources/css/labkipackmanager.css', include: ['resources/src/styles/**/*.scss'], sass }),
    // Collect all SFC CSS into a single file for RL
    css({ output: 'resources/css/lpm-sfc.css' }),
    resolve(),
    commonjs(),
    terser.default ? terser.default() : terser()
  ]
};
