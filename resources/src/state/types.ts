/**
 * TypeScript type definitions for Labki Pack Manager state management.
 *
 * These types mirror the PHP PackSessionState domain model and API response structures.
 */

// ============================================================================
// Pack Session State Types
// ============================================================================

/**
 * State for an individual page within a pack.
 */
export interface PackPageState {
  /** Original page name from manifest */
  name: string;
  /** Computed default title (prefix/name) */
  default_title?: string;
  /** User-customized title or default */
  final_title?: string;
  /** Whether this title conflicts with existing page */
  has_conflict?: boolean;
  /** Type of conflict (title_exists, namespace_invalid, etc.) */
  conflict_type?: string | null;
  /** Whether this page is already installed */
  installed?: boolean;
}

/**
 * State for a pack including all its pages.
 */
export interface PackState {
  /** Action type: install|update|remove|unchanged */
  action?: 'install' | 'update' | 'remove' | 'unchanged';
  /** Reason for auto-action (null if manually set by user, otherwise explains why) */
  auto_selected_reason?: string | null;
  /** Version currently installed (null if not installed) */
  current_version?: string | null;
  /** Version from manifest */
  target_version?: string | null;
  /** Pack prefix for page titles (user-customizable) */
  prefix?: string;
  /** Whether this pack is already installed */
  installed?: boolean;
  /** Pages within this pack */
  pages?: Record<string, PackPageState>;
}

/**
 * Complete pack state collection.
 */
export type PacksState = Record<string, PackState>;

// ============================================================================
// API Request/Response Types
// ============================================================================

/**
 * Payload for labkiPacksAction API.
 */
export interface PacksActionPayload {
  /** Command name (init, set_pack_action, rename_page, etc.) */
  command: string;
  /** Repository URL */
  repo_url: string;
  /** Reference (branch/tag) */
  ref: string;
  /** Command-specific data */
  data: Record<string, unknown>;
}

/**
 * Response from labkiPacksAction API.
 */
export interface PacksActionResponse {
  /** Success flag */
  ok: boolean;
  /** State diff (changed fields only, or full state on init) */
  diff: PacksState;
  /** Warning messages */
  warnings: string[];
  /** Authoritative server state hash */
  state_hash: string;
  /** Operation info (e.g., from apply command) */
  operation?: {
    operation_id?: string;
    status?: string;
    [key: string]: unknown;
  };
  /** Response metadata */
  meta: {
    schemaVersion: number;
    timestamp: string;
  };
}

// ============================================================================
// Hierarchy Types
// ============================================================================

/**
 * Statistics for a hierarchy node.
 */
export interface NodeStats {
  /** Number of pack nodes beneath this node */
  packs_beneath: number;
  /** Number of page nodes beneath this node */
  pages_beneath: number;
}

/**
 * A node in the hierarchy tree (pack or page).
 */
export interface HierarchyNode {
  /** Node ID (pack:name or page:name) */
  id: string;
  /** Display label */
  label: string;
  /** Node type */
  type: 'pack' | 'page';
  /** Pack description (packs only) */
  description?: string;
  /** Pack version (packs only) */
  version?: string;
  /** Dependencies (pack names, packs only) */
  depends_on?: string[];
  /** Child nodes */
  children?: HierarchyNode[];
  /** Statistics (packs only) */
  stats?: NodeStats;
}

/**
 * Complete hierarchy structure.
 */
export interface Hierarchy {
  /** Root-level nodes */
  root_nodes: HierarchyNode[];
  /** Metadata */
  meta: {
    pack_count: number;
    page_count: number;
    timestamp: string;
  };
}

// ============================================================================
// Repository & Ref Types
// ============================================================================

/**
 * Information about a content ref (branch/tag).
 */
export interface Ref {
  /** Ref ID */
  ref_id: number;
  /** Source ref name (e.g., "main", "v1.0.0") */
  ref: string;
  /** Display name for ref */
  ref_name: string;
  /** Whether this is the default ref for the repo */
  is_default: boolean;
  /** Last commit hash */
  last_commit: string;
  /** Manifest hash */
  manifest_hash: string;
  /** When manifest was last parsed */
  manifest_last_parsed?: string;
  /** Created timestamp */
  created_at: string;
  /** Updated timestamp */
  updated_at: string;
}

/**
 * Information about a content repository.
 */
export interface Repo {
  /** Repository ID */
  repo_id: number;
  /** Repository URL */
  repo_url: string;
  /** Default ref name */
  default_ref: string;
  /** When repository was last fetched */
  last_fetched: string;
  /** All refs for this repository */
  refs: Ref[];
  /** Number of refs */
  ref_count: number;
  /** Last sync timestamp (computed from refs) */
  last_synced: string | null;
  /** Created timestamp */
  created_at: string;
  /** Updated timestamp */
  updated_at: string;
}

/**
 * Response from labkiReposList API.
 */
export interface ReposListResponse {
  repos: Repo[];
  meta: {
    schemaVersion: number;
    timestamp: string;
  };
}

/**
 * Response from labkiReposAdd API.
 */
export interface ReposAddResponse {
  ok: boolean;
  operation_id: string;
  repo_url: string;
  default_ref: string;
  meta: {
    schemaVersion: number;
    timestamp: string;
  };
}

// ============================================================================
// Graph Types
// ============================================================================

/**
 * Response from labkiGraphGet API.
 */
export interface GraphGetResponse {
  repo_url: string;
  ref: string;
  hash: string;
  graph: {
    containsEdges: { from: string; to: string }[];
    dependsEdges: { from: string; to: string }[];
    roots: string[];
    hasCycle: boolean;
  };
  meta: {
    schemaVersion: number;
    timestamp: string;
    from_cache: boolean;
  };
}

/**
 * Response from labkiHierarchyGet API.
 */
export interface HierarchyGetResponse {
  repo_url: string;
  ref: string;
  hash: string;
  hierarchy: Hierarchy;
  meta: {
    schemaVersion: number;
    timestamp: string;
    from_cache: boolean;
  };
}
