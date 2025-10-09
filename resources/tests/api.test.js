import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fetchManifestFor } from '../src/api.js';

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
});


