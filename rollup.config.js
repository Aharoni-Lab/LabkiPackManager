import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import terser from '@rollup/plugin-terser';
import typescript from '@rollup/plugin-typescript';
import vue from 'rollup-plugin-vue';
import scss from 'rollup-plugin-scss';
import css from 'rollup-plugin-css-only';
import * as sass from 'sass';

export default {
  input: 'resources/src/main.ts',
  output: {
    file: 'resources/modules/ext.LabkiPackManager/app.bundle.js',
    format: 'iife',
    name: 'LabkiPackManager',
    sourcemap: true,
    globals: {
      'vue': 'Vue',
      '@wikimedia/codex': 'Codex',
      'mermaid': 'mermaid'
    },
    banner: `
      var Vue = (typeof mw!=="undefined" && mw.loader && mw.loader.require)
        ? mw.loader.require("vue")
        : window.Vue;
      var Codex = (typeof mw!=="undefined" && mw.loader && mw.loader.require)
        ? (mw.loader.require("@wikimedia/codex") || mw.loader.require("codex"))
        : window.Codex;
      var mermaid = (function(){
        try {
          if (typeof window!=="undefined" && window.mermaid) return window.mermaid;
          if (typeof mw!=="undefined" && mw.loader && mw.loader.require) {
            var mod = mw.loader.require("ext.mermaid");
            if (mod?.initialize) return mod;
            if (mod?.mermaid?.initialize) return mod.mermaid;
            if (mod?.default?.initialize) return mod.default;
          }
        } catch(e){}
        return window.mermaid;
      })();
    `
  },
  external: ['vue', '@wikimedia/codex', 'mermaid'],
  plugins: [
    vue({ 
      css: false,
      preprocessStyles: false
    }),
    typescript({
      tsconfig: './tsconfig.json',
      sourceMap: true,
      declaration: false,
      exclude: ['**/*.spec.ts', '**/*.test.ts']
    }),
    scss({
      output: 'resources/css/labkipackmanager.css',
      include: ['resources/src/styles/**/*.scss'],
      sass
    }),
    css({ output: 'resources/css/lpm-sfc.css' }),
    resolve({
      extensions: ['.ts', '.js', '.vue']
    }),
    commonjs(),
    terser.default ? terser.default() : terser()
  ]
};
