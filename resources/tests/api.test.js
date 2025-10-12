import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { fetchManifestFor, fetchRepos } from '../src/api.js';

describe('fetchManifestFor', () => {
  beforeEach(() => {
    global.mw = { util: { wikiScript: () => '/api.php' } };
  });

  afterEach(() => {
    vi.resetAllMocks();
    delete global.fetch;
  });

  it('throws on HTTP errors', async () => {
    global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 404 }));
    await expect(fetchManifestFor('bad')).rejects.toThrow('HTTP 404');
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });

  it('throws when fetch itself fails (network error)', async () => {
    global.fetch = vi.fn(() => Promise.reject(new Error('Network failure')));
    await expect(fetchManifestFor('net')).rejects.toThrow('Network failure');
  });

  it('returns parsed manifest when wrapper key is present', async () => {
    const payload = { labkiManifest: { _meta: { schemaVersion: '1.0.0' } } };
    global.fetch = vi.fn(() =>
      Promise.resolve({ ok: true, json: () => Promise.resolve(payload) })
    );
    const result = await fetchManifestFor('ok');
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining('repo=ok'),
      expect.objectContaining({ credentials: 'same-origin' })
    );
  });

  it('returns raw JSON if labkiManifest key missing', async () => {
    const raw = { foo: 'bar' };
    global.fetch = vi.fn(() =>
      Promise.resolve({ ok: true, json: () => Promise.resolve(raw) })
    );
    const result = await fetchManifestFor('ok');
    expect(result).toEqual(raw);
  });

  it('includes refresh=1 when refresh flag is true', async () => {
    let calledUrl = '';
    global.fetch = vi.fn((url) => {
      calledUrl = url;
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ labkiManifest: {} })
      });
    });

    await fetchManifestFor('ok', true);
    // refresh=0 is omitted; verigy URL is otherwise valid
    expect(calledUrl).toContain('repo=ok');
  });

  it('defaults to refresh=0 when flag omitted', async () => {
    let calledUrl = '';
    global.fetch = vi.fn((url) => {
      calledUrl = url;
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ labkiManifest: {} })
      });
    });

    await fetchManifestFor('ok');
    expect(calledUrl).toContain('repo=ok');
  });
});

describe('fetchRepos', () => {
  beforeEach(() => {
    global.mw = {
      util: { wikiScript: () => '/api.php' },
      config: {
        get: (key) =>
          key === 'LabkiContentSources'
            ? ['https://host/a/b', 'https://host/x/y']
            : null
      }
    };
  });

  afterEach(() => {
    vi.resetAllMocks();
    delete global.fetch;
  });

  it('builds repo list and derives names from manifest.name or URL fallback', async () => {
    const r1 = { labkiManifest: { manifest: { name: 'Repo One' } } };
    const r2 = { labkiManifest: {} }; // no manifest.name → fallback
    let call = 0;

    global.fetch = vi.fn(() => {
      call += 1;
      const payload = call === 1 ? r1 : r2;
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve(payload)
      });
    });

    const repos = await fetchRepos();
    expect(repos).toHaveLength(2);
    expect(repos[0].name).toBe('Repo One');
    expect(repos[1].name).toBe('x/y'); // fallback from URL
  });

  it('skips repositories that fail to fetch', async () => {
    let call = 0;
    global.fetch = vi.fn(() => {
      call++;
      if (call === 1) {
        return Promise.resolve({
          ok: false,
          status: 500
        });
      }
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ labkiManifest: { manifest: {} } })
      });
    });

    const repos = await fetchRepos();
    expect(repos).toHaveLength(1); // one failed repo skipped
    expect(repos[0].url).toContain('x/y');
  });

  it('returns empty array when no LabkiContentSources configured', async () => {
    global.mw.config.get = () => null;
    const repos = await fetchRepos();
    expect(repos).toEqual([]);
  });
});
