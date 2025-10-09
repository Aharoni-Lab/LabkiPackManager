import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fetchManifestFor, fetchRepos } from '../src/api.js';

describe('fetchManifestFor', () => {
  beforeEach(() => {
    global.mw = { util: { wikiScript: () => '/api.php' } };
  });

  it('throws on HTTP errors', async () => {
    global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 404 }));
    await expect(fetchManifestFor('bad')).rejects.toThrow('HTTP 404');
  });

  it('returns parsed manifest on success', async () => {
    const payload = { labkiManifest: { _meta: { schemaVersion: '1.0.0' } } };
    global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(payload) }));
    const res = await fetchManifestFor('ok');
    expect(res).toEqual(payload.labkiManifest);
  });

  it('returns raw JSON when wrapper missing', async () => {
    const raw = { foo: 'bar' };
    global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(raw) }));
    const res = await fetchManifestFor('ok');
    expect(res).toEqual(raw);
  });

  it('includes refresh=1 when refresh flag is true', async () => {
    let calledUrl = '';
    global.fetch = vi.fn((url) => {
      calledUrl = url;
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ labkiManifest: {} }) });
    });
    await fetchManifestFor('ok', true);
    expect(calledUrl).toContain('refresh=1');
  });
});

describe('fetchRepos', () => {
  beforeEach(() => {
    global.mw = {
      util: { wikiScript: () => '/api.php' },
      config: { get: (key) => (key === 'LabkiContentSources' ? ['https://host/a/b', 'https://host/x/y'] : null) }
    };
  });

  it('builds repo list and derives names from manifest.name or URL fallback', async () => {
    const r1 = { labkiManifest: { manifest: { name: 'Repo One' } } };
    const r2 = { labkiManifest: {} }; // no manifest.name → fallback
    let call = 0;
    global.fetch = vi.fn(() => {
      call += 1;
      const payload = call === 1 ? r1 : r2;
      return Promise.resolve({ ok: true, json: () => Promise.resolve(payload) });
    });

    const repos = await fetchRepos();
    expect(repos).toHaveLength(2);
    expect(repos[0].name).toBe('Repo One');
    expect(repos[1].name).toBe('x/y'); // last two segments fallback
  });
});


