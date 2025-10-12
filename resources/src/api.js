/**
 * LabkiPackManager – API Layer
 * ------------------------------------------------------------
 * Provides helper functions for fetching manifests and repo data
 * through the MediaWiki backend API. All functions are promise-based
 * and return plain JavaScript objects suitable for Vue state usage.
 *
 * Exports:
 *   - fetchManifestFor(repoUrl, refresh)
 *   - fetchRepos()
 *   - fetchInstalledFor(repoUrl)
 *   - migrateV2(manifest)
 */

/**
 * Internal helper: safely get Labki content sources from mw.config.
 * @returns {string[]} List of repository URLs (may be empty).
 */
function getConfiguredRepos() {
  if (typeof mw === 'undefined' || !mw.config) return [];
  const cfg =
    mw.config.get('LabkiContentSources') ||
    mw.config.get('wgLabkiContentSources');
  return Array.isArray(cfg) ? cfg : [];
}

/**
 * Fetch a manifest JSON for a given repository URL via MediaWiki API.
 *
 * @async
 * @param {string} repoUrl - Repository URL or key.
 * @param {boolean} [refresh=false] - If true, bypass cache on server side.
 * @returns {Promise<Object>} Parsed manifest payload (labkiManifest or raw JSON).
 * @throws {Error} When the HTTP response fails or response body is invalid.
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

  let res;
  try {
    res = await fetch(url, { credentials: 'same-origin' });
  } catch (networkError) {
    throw new Error(
      `Network error fetching manifest for ${repoUrl}: ${networkError}`
    );
  }

  if (!res.ok) {
    throw new Error(`HTTP ${res.status} fetching manifest for ${repoUrl}`);
  }

  let json;
  try {
    json = await res.json();
  } catch (parseError) {
    throw new Error(`Invalid JSON from ${repoUrl}: ${parseError}`);
  }

  // Allow either { labkiManifest: { ... } } or raw manifest
  return json.labkiManifest || json;
}

/**
 * Fetch all configured repositories and return their info and cached data.
 *
 * Uses mw.config('LabkiContentSources' | 'wgLabkiContentSources').
 * Each repo is fetched concurrently via fetchManifestFor().
 *
 * @async
 * @returns {Promise<Array<{url:string,name:string,data?:Object}>>}
 */
export async function fetchRepos() {
  const urls = getConfiguredRepos();
  if (urls.length === 0) {
    console.warn('[LabkiPackManager] No LabkiContentSources defined.');
    return [];
  }

  const results = await Promise.allSettled(
    urls.map((url) => fetchManifestFor(url, false))
  );

  const repos = [];
  results.forEach((res, i) => {
    const url = urls[i];
    if (res.status === 'fulfilled' && res.value) {
      const data = res.value;
      const name =
        data?._meta?.repoName ||
        data?.manifest?.name ||
        url.split('/').slice(-2).join('/');
      repos.push({ url, name, data });
    } else {
      console.warn(
        `[LabkiPackManager] Repo ${url} failed:`,
        res.reason instanceof Error ? res.reason.message : res.reason
      );
    }
  });

  return repos;
}

/**
 * Fetch installed packs for a given repository from the local DB via ApiLabkiQuery.
 * Returns an array of { name, version, ... } entries.
 *
 * @async
 * @param {string} repoUrl
 * @returns {Promise<Array<{name:string,version:string}>>}
 */
export async function fetchInstalledFor(repoUrl) {
  const base = mw.util.wikiScript('api');
  const params = new URLSearchParams({
    action: 'labkiquery',
    format: 'json',
    formatversion: '2',
    repo: repoUrl
  });

  const url = `${base}?${params.toString()}`;

  let res;
  try {
    res = await fetch(url, { credentials: 'same-origin' });
  } catch (networkError) {
    throw new Error(`Network error fetching installed packs for ${repoUrl}: ${networkError}`);
  }

  if (!res.ok) {
    throw new Error(`HTTP ${res.status} fetching installed packs for ${repoUrl}`);
  }

  let json;
  try {
    json = await res.json();
  } catch (parseError) {
    throw new Error(`Invalid JSON from installed packs for ${repoUrl}: ${parseError}`);
  }

  const payload = json && (json.labkiquery || json);
  const packs = Array.isArray(payload?.packs) ? payload.packs : [];
  return packs;
}

/**
 * Placeholder migration: convert schema v2 manifests to the v1 structure.
 * Extend this function when schema v2 is formalized.
 *
 * @param {Object} manifest - Raw v2 manifest.
 * @returns {Object} - Migrated v1-compatible manifest.
 */
export function migrateV2(manifest) {
  return manifest;
}
