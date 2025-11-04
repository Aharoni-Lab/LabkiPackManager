/**
 * Reactive state store for Labki Pack Manager.
 * 
 * Uses Vue's reactive() for minimal, framework-integrated state management.
 */

import { reactive } from 'vue';
import type { PacksState, Hierarchy, Repo } from './types';

/**
 * Global reactive store.
 */
export const store = reactive({
  /** Currently selected repository URL */
  repoUrl: '' as string,

  /** Currently selected ref (branch/tag) */
  ref: '' as string,

  /** Raw Mermaid source code from API */
  mermaidSrc: '' as string,

  /** Hierarchy tree structure */
  hierarchy: null as Hierarchy | null,

  /** Pack session state (mirrored from server) */
  packs: {} as PacksState,

  /** Server state hash */
  stateHash: '' as string,

  /** Warning messages from API */
  warnings: [] as string[],

  /** Loading/busy state */
  busy: false as boolean,

  /** Currently selected repository object */
  selectedRepo: null as Repo | null,

  /** All available repositories */
  repos: [] as Repo[],
});

