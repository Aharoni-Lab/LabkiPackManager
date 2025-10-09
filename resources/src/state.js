/**
 * LabkiPackManager – Application State Factory
 * ------------------------------------------------------------
 * Defines the reactive state used by the Vue root app.
 * This factory returns a fresh, plain JS object each time it is called,
 * ensuring independent reactivity for each mounted app instance.
 */

/**
 * Create the initial reactive state for the LabkiPackManager app.
 * @returns {import('./state.d.ts').LpmState}
 */
export function createInitialState() {
  return {
    /**
     * Current manifest data loaded for the active repository.
     * This may include `hierarchy`, `graph`, etc.
     * @type {Object|null}
     */
    data: null,

    /**
     * The currently active repository URL or key.
     * @type {string|null}
     */
    activeRepo: null,

    /**
     * Repository list (from mw.config LabkiContentSources)
     * Each entry: { url, name, data? }
     */
    repos: [],

    /** Whether a repo manifest is currently being loaded. */
    isLoadingRepo: false,

    /** Whether the current manifest is being refreshed from the backend. */
    isRefreshing: false,

    // ------------------------------------------------------------
    // UI Helpers
    // ------------------------------------------------------------

    /**
     * Menu items for repository selection dropdown.
     * Each: { label, value }
     */
    repoMenuItems: [],

    /**
     * Message stack for transient UI feedback.
     * Each message: { id, type, text }
     */
    messages: [],

    /** Counter for generating unique message IDs. */
    nextMsgId: 1,

    // ------------------------------------------------------------
    // Dialogs
    // ------------------------------------------------------------

    /** Whether the "Import" confirmation dialog is visible. */
    showImportConfirm: false,

    /** Whether the "Update" confirmation dialog is visible. */
    showUpdateConfirm: false,

    // ------------------------------------------------------------
    // Tree Interaction State
    // ------------------------------------------------------------

    /** Selected packs (packName → boolean). */
    selectedPacks: {},

    /** Selected pages (pack::page → boolean). */
    selectedPages: {},

    /** Optional prefixes applied per pack (packName → prefix string). */
    prefixes: {},

    /** Optional renames applied per page (pack::page → newName string). */
    renames: {}
  };
}

/**
 * (Optional) Reset state helper for future extensibility.
 * Returns a shallow-cloned version of the initial state.
 * Useful for "reset all" or test reinitialization.
 */
export function cloneInitialState() {
  return JSON.parse(JSON.stringify(createInitialState()));
}
