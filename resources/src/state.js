// Factory for initial app state used by the Vue root.
export function createInitialState() {
  return {
    data: null,
    activeRepo: null,
    repos: [],
    isLoadingRepo: false,
    isRefreshing: false,

    // UI helpers
    repoMenuItems: [],
    messages: [], // { id, type, text }
    nextMsgId: 1,

    // Dialog state
    showImportConfirm: false,
    showUpdateConfirm: false,

    // Tree interaction state
    selectedPacks: {},
    selectedPages: {},
    prefixes: {},
    renames: {}
  };
}


