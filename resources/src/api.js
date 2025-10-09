/**
 * Fetch a manifest JSON for a given repository URL via MediaWiki API.
 * @param {string} repoUrl Repository URL or key.
 * @param {boolean} [refresh=false] If true, bypass cache on server side.
 * @returns {Promise<Object>} Parsed manifest payload (labkiManifest or raw JSON).
 * @throws {Error} When the HTTP response is not OK.
 */
export async function fetchManifestFor(repoUrl, refresh = false) {
  const base = mw.util.wikiScript('api');
  const params = new URLSearchParams({
    action: 'labkiManifest',
    format: 'json',
    formatversion: '2',
    repo: repoUrl
  });
  if (refresh) params.set('refresh', '1');
  const url = `${base}?${params.toString()}`;
  const res = await fetch(url, { credentials: 'same-origin' });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const json = await res.json();
  return json.labkiManifest || json;
}

/**
 * Fetch all configured repositories and return their basic info and cached data.
 * Uses mw.config LabkiContentSources.
 * @returns {Promise<Array<{url:string,name:string,data?:Object}>>}
 */
export async function fetchRepos() {
  const cfg = (typeof mw !== 'undefined' && mw.config)
    ? (mw.config.get('LabkiContentSources') || mw.config.get('wgLabkiContentSources'))
    : null;
  const urls = Array.isArray(cfg) ? cfg : [];
  if (urls.length === 0) return [];
  const results = await Promise.allSettled(urls.map((u) => fetchManifestFor(u, false)));
  return urls.map((u, i) => {
    const r = results[i];
    if (r.status === 'fulfilled' && r.value) {
      const data = r.value;
      let name = data?._meta?.repoName || data?.manifest?.name || u.split('/').slice(-2).join('/');
      return { url: u, name, data };
    }
    return { url: u, name: `${u} (unavailable)` };
  });
}

/**
 * Migrate schema v2 manifest to current app structure.
 * No-op placeholder until schema v2 arrives.
 * @param {Object} manifest
 * @returns {Object}
 */
export function migrateV2(manifest) {
  // Placeholder migration from hypothetical v2 to v1-compatible structure.
  // Adjust when schema evolves.
  return manifest;
}

/**
 * Normalize a manifest based on its declared schema version.
 * @param {Object} manifest Parsed manifest object
 * @returns {Object} Normalized manifest
 * @throws {Error} If schema version is unknown/missing
 */
export function normalizeManifest(manifest) {
  const ver = manifest?._meta?.schemaVersion || manifest?._meta?.schema_version;
  switch (ver) {
    case '1.0.0':
      return manifest;
    case '2.0.0':
      return migrateV2(manifest);
    default:
      throw new Error(`Unknown schema version ${ver}`);
  }
}


