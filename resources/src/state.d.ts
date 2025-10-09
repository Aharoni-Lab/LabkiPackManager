export interface LpmState {
  data: any | null;
  activeRepo: string | null;
  repos: Array<{ url: string; name: string; data?: any }>;
  isLoadingRepo: boolean;
  isRefreshing: boolean;
  repoMenuItems: Array<{ label: string; value: string }>;
  messages: Array<{ id: number; type: string; text: string }>;
  nextMsgId: number;
  showImportConfirm: boolean;
  showUpdateConfirm: boolean;
  selectedPacks: Record<string, boolean>;
  selectedPages: Record<string, boolean>;
  prefixes: Record<string, string>;
  renames: Record<string, string>;
}

export function createInitialState(): LpmState;


