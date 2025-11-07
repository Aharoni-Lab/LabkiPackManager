/**
 * Vue single file component type shim.
 */

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<object, object>;
  export default component;
}
