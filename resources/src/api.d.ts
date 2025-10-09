/**
 * LabkiPackManager – API Type Declarations
 * ------------------------------------------------------------
 * These type definitions describe the shape of data returned by
 * the MediaWiki Labki API backend. They are consumed by the JS
 * implementation in api.js and provide IntelliSense and validation
 * for IDEs and TypeScript-aware tooling.
 */

/**
 * Minimal metadata section expected in every manifest.
 */
export interface ManifestMeta {
    /** Declared schema version, e.g. "1.0.0" */
    schemaVersion?: string;
    /** Optional repository name (for display) */
    repoName?: string;
    /** Additional meta fields (optional / schema-specific) */
    [key: string]: any;
  }
  
  /**
   * Top-level manifest structure returned from a Labki repository.
   * This is intentionally flexible because the schema may evolve.
   */
  export interface LabkiManifest {
    /** Metadata about the manifest and schema version. */
    _meta?: ManifestMeta;
    /** Manifest name, if available. */
    manifest?: { name?: string };
    /** Hierarchical pack and page data. */
    hierarchy?: Record<string, any>;
    /** Optional graph representation. */
    graph?: Record<string, any>;
    /** Any other version-specific fields. */
    [key: string]: any;
  }
  
  /**
   * Represents a repository configuration and its cached data.
   */
  export interface RepoEntry {
    /** Repository URL or identifier key. */
    url: string;
    /** Display name (derived from manifest or URL). */
    name: string;
    /** Cached manifest data, if available. */
    data?: LabkiManifest;
  }
  
  /**
   * Fetch a manifest JSON for a given repository URL via the MediaWiki API.
   * @param repoUrl Repository URL or key.
   * @param refresh If true, bypass cache on server side.
   * @returns Parsed manifest payload (labkiManifest or raw JSON).
   * @throws When the HTTP request or JSON parsing fails.
   */
  export function fetchManifestFor(
    repoUrl: string,
    refresh?: boolean
  ): Promise<LabkiManifest>;
  
  /**
   * Fetch all configured repositories and return their info and cached data.
   * Uses `mw.config` for `LabkiContentSources`.
   * @returns Array of repository entries with optional cached manifests.
   */
  export function fetchRepos(): Promise<RepoEntry[]>;
  
  /**
   * Migrate schema v2 manifests to the v1-compatible structure.
   * This is a no-op until schema v2 is formally released.
   * @param manifest Raw v2 manifest.
   * @returns Migrated manifest compatible with current schema.
   */
  export function migrateV2(manifest: LabkiManifest): LabkiManifest;
  
  /**
   * Normalize a manifest according to its declared schema version.
   * Throws if schema version is missing or unsupported.
   * @param manifest Parsed manifest object.
   * @returns Normalized manifest ready for use in the app.
   */
  export function normalizeManifest(manifest: LabkiManifest): LabkiManifest;
  