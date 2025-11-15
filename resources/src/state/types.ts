/**
 * TypeScript type definitions for Labki Pack Manager state management.
 *
 * These types mirror the PHP PackSessionState domain model and API response structures.
 */

// base types

export type ActionAPIName =
  | 'labkiReposList'
  | 'labkiReposAdd'
  | 'labkiGraphGet'
  | 'labkiHierarchyGet'
  | 'labkiPacksAction'
  | 'labkiOperationsStatus'
  | 'labkiReposSync';
export interface ActionAPIResponseBase {
  meta: {
    schemaVersion: number;
    timestamp: string;
    from_cache?: boolean;
  };
}

export interface ActionAPIRequestBase<T extends ActionAPIName> {
  action: T;
  format: 'json';
}

export interface RepoRefRequest<T extends ActionAPIName> extends ActionAPIRequestBase<T> {
  repo_url: string;
  ref: string;
}

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

export type PackStateAction = 'install' | 'update' | 'remove' | 'unchanged';

/**
 * State for a pack including all its pages.
 */
export interface PackState {
  /** Action type: install|update|remove|unchanged */
  action?: PackStateAction;
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

export type PacksActionCommandName =
  | 'init'
  | 'refresh'
  | 'clear'
  | 'set_pack_action'
  | 'rename_page'
  | 'set_pack_prefix'
  | 'apply';

export type OperationStatus = 'queued' | 'running' | 'success' | 'failed';

/**
 * Payload for labkiPacksAction API.
 * A base type for common properties, sub-interfaces for each specific command,
 * and then a pair of selector types to be able to match types by the value of `command`
 */
export interface PacksActionCommandBase {
  /** Command name (init, set_pack_action, rename_page, etc.) */
  command: PacksActionCommandName;
  /** Repository URL */
  repo_url: string;
  /** Reference (branch/tag) */
  ref: string;
  /** Command-specific data */
  data: PacksActionDataBase;
  pack_name?: string;
}

export interface PacksActionDataBase {
  pack_name: string;
}

export interface SetPackActionCommand extends PacksActionCommandBase {
  command: 'set_pack_action';
  pack_name: string;
  data: SetPackActionData;
}

export interface SetPackActionData extends PacksActionDataBase {
  action: string;
}

export interface SetPackPrefixCommand extends PacksActionCommandBase {
  command: 'set_pack_prefix';
  data: SetPackPrefixData;
}

export interface SetPackPrefixData extends PacksActionDataBase {
  prefix: string;
}

export interface RenamePageCommand extends PacksActionCommandBase {
  command: 'rename_page';
  data: RenamePageData;
}

export interface RenamePageData extends PacksActionDataBase {
  page_name: string;
  new_title: string;
}

export interface PacksActionRequest extends ActionAPIRequestBase<'labkiPacksAction'> {
  payload: string;
}

export type PacksActionCommand = SetPackActionCommand | SetPackPrefixCommand | RenamePageCommand;

export interface PacksActionDataMap {
  set_pack_action: SetPackActionData;
  set_pack_prefix: SetPackPrefixData;
  rename_page: RenamePageData;
  init: never;
  refresh: never;
  clear: never;
  apply: never;
}

/**
 * Response from labkiPacksAction API.
 */
export interface PacksActionResponse extends ActionAPIResponseBase {
  /** Success flag */
  ok: boolean;
  /** Optional error code when ok === false */
  error?: string;
  /** Human-friendly message */
  message?: string;
  /** State diff (changed fields only, or full state on init) */
  diff: PacksState;
  /** Warning messages */
  warnings: string[];
  /** Authoritative server state hash */
  state_hash: string;
  /** Operation info (e.g., from apply command) */
  operation?: {
    operation_id?: string;
    status?: OperationStatus;
  };
  /** Authoritative server packs (when state is out of sync) */
  server_packs?: PacksState;
  /** Field-level differences for reconciliation */
  differences?: StateDifference;
  /** Suggested commands to reconcile client intent */
  reconcile_commands?: PacksActionCommand[];
  /** Response metadata */
  meta: {
    schemaVersion: number;
    timestamp: string;
  };
}

// idk why but this is nested???
export interface PacksActionWrapper {
  labkiPacksAction: PacksActionResponse;
}

export type StateDifference = Record<string, PackDifference>;

export interface PackDifference {
  fields?: Record<string, FieldDifference>;
  pages?: Record<string, Record<string, FieldDifference>>;
}

export interface FieldDifference {
  client: string;
  server: string;
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
export interface ReposListResponse extends ActionAPIResponseBase {
  repos: Repo[];
}

/**
 * Response from labkiReposAdd API.
 */
export interface ReposAddRequest extends ActionAPIRequestBase<'labkiReposAdd'> {
  repo_url: string;
  default_ref?: string;
  refs?: string[];
}

export interface ReposAddResponse extends ActionAPIResponseBase {
  success: boolean;
  operation_id: string;
  repo_url: string;
  default_ref: string;
  status?: string;
  message?: string;
  refs?: Ref[];
}

export interface ReposSyncRequest extends ActionAPIRequestBase<'labkiReposSync'> {
  repo_url: string;
  refs?: string;
}

export interface ReposSyncResponse extends ActionAPIResponseBase {
  success: boolean;
  operation_id: string;
  status: OperationStatus;
  message: string;
  refs?: Ref[];
}

// ============================================================================
// Graph Types
// ============================================================================

/**
 * Response from labkiGraphGet API.
 */

export interface PackGraphEdge {
  from: string;
  to: string;
}

export interface PackGraph {
  containsEdges: PackGraphEdge[];
  dependsEdges: PackGraphEdge[];
  roots: string[];
  hasCycle: boolean;
}

export interface GraphGetResponse extends ActionAPIResponseBase {
  repo_url: string;
  ref: string;
  hash: string;
  graph: PackGraph;
}

/**
 * Response from labkiHierarchyGet API.
 */
export interface HierarchyGetResponse extends ActionAPIResponseBase {
  repo_url: string;
  ref: string;
  hash: string;
  hierarchy: Hierarchy;
}

export interface OperationsStatusRequest extends ActionAPIRequestBase<'labkiOperationsStatus'> {
  operation_id: string;
}

export interface OperationsStatusResponse extends ActionAPIResponseBase {
  status: string;
  operation_id: string;
  message: string;
  progress: number;
}

// Type unions for api.get and api.post methods
// using a combination of conditional types with a generic and a mapping
// so that the `action` within the request can be linked to the response type

export type ActionAPIRequest<T extends ActionAPIName> = T extends 'labkiReposList'
  ? ActionAPIRequestBase<T>
  : T extends 'labkiReposAdd'
    ? ReposAddRequest
    : T extends 'labkiGraphGet'
      ? RepoRefRequest<T>
      : T extends 'labkiHierarchyGet'
        ? RepoRefRequest<T>
        : T extends 'labkiPacksAction'
          ? PacksActionRequest
          : T extends 'labkiOperationsStatus'
            ? OperationsStatusRequest
            : T extends 'labkiReposSync'
              ? ReposSyncRequest
              : never;

export interface ActionAPIResponseMap {
  labkiReposList: ReposListResponse;
  labkiReposAdd: ReposAddResponse;
  labkiGraphGet: GraphGetResponse;
  labkiHierarchyGet: HierarchyGetResponse;
  labkiPacksAction: PacksActionWrapper;
  labkiOperationsStatus: OperationsStatusResponse;
  labkiReposSync: ReposSyncResponse;
}
