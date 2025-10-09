export function fetchManifestFor(repoUrl: string, refresh?: boolean): Promise<any>;
export function fetchRepos(): Promise<Array<{ url: string; name: string; data?: any }>>;
export function migrateV2(manifest: any): any;
export function normalizeManifest(manifest: any): any;


