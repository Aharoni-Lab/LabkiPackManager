/**
 * LabkiPackManager – State Type Definitions
 * ------------------------------------------------------------
 * Defines the shape of the reactive Vue app state.
 * Mirrors the object returned by createInitialState().
 */

import type { LabkiManifest, RepoEntry } from './api.js';

/**
 * A single message entry in the message queue.
 */
export interface LpmMessage {
  /** Unique numeric ID used for dismissal. */
  id: number;
  /** One of MSG_TYPES (success, error, info, warning). */
  type: string;
  /** User-visible text. */
  text: string;
}

/**
 * LabkiPackManager root Vue state.
 */
export interface LpmState {
  /** Current manifest data for the active repository. */
  data: LabkiManifest | null;

  /** Active repository URL/key. */
  activeRepo: string | null;

  /** List of repositories fetched from MediaWiki config. */
  repos: RepoEntry[];

  /** Whether a repository manifest is being loaded. */
  isLoadingRepo: boolean;

  /** Whether a refresh operation is in progress. */
  isRefreshing: boolean;

  /** Menu items for repository selection dropdown. */
  repoMenuItems: Array<{ label: string; value: string }>;

  /** Transient message stack (notifications). */
  messages: LpmMessage[];

  /** Counter for assigning unique message IDs. */
  nextMsgId: number;

  /** Visibility of Import confirmation dialog. */
  showImportConfirm: boolean;

  /** Visibility of Update confirmation dialog. */
  showUpdateConfirm: boolean;

  /** Selected packs keyed by pack name. */
  selectedPacks: Record<string, boolean>;

  /** Selected pages keyed by "pack::page" composite key. */
  selectedPages: Record<string, boolean>;

  /** Pack name → prefix string mapping. */
  prefixes: Record<string, string>;

  /** "pack::page" → renamed page name mapping. */
  renames: Record<string, string>;
}

/**
 * Factory for creating a new instance of the reactive Vue app state.
 */
export function createInitialState(): LpmState;

/**
 * Optional helper that returns a deep-cloned copy of the initial state.
 */
export function cloneInitialState(): LpmState;
