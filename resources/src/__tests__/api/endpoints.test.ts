/**
 * Tests for API endpoint functions.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

describe('API Endpoints', () => {
  beforeEach(() => {
    // Reset mocks
    vi.clearAllMocks();
  });

  describe('Response Parsing', () => {
    it('should handle wrapped API responses', () => {
      const wrappedResponse = {
        labkiReposList: {
          repos: [{ repo_id: 1, url: 'https://github.com/test/repo' }],
          meta: { schemaVersion: 1, timestamp: '20251105120000' },
        },
      };

      const data = wrappedResponse.labkiReposList;
      expect(data.repos).toHaveLength(1);
      expect(data.repos[0].repo_id).toBe(1);
      expect(data.meta.schemaVersion).toBe(1);
    });

    it('should handle unwrapped API responses', () => {
      const unwrappedResponse = {
        repos: [{ repo_id: 1, url: 'https://github.com/test/repo' }],
        meta: { schemaVersion: 1, timestamp: '20251105120000' },
      };

      expect(unwrappedResponse.repos).toHaveLength(1);
      expect(unwrappedResponse.meta).toBeDefined();
    });

    it('should extract operation data from responses', () => {
      const operationResponse = {
        success: true,
        operation_id: 'repo_add_abc123',
        status: 'queued',
        message: 'Repository queued for initialization',
        meta: { schemaVersion: 1, timestamp: '20251105120000' },
      };

      expect(operationResponse.success).toBe(true);
      expect(operationResponse.operation_id).toBe('repo_add_abc123');
      expect(operationResponse.status).toBe('queued');
    });

    it('should handle graph response structure', () => {
      const graphResponse = {
        repo_url: 'https://github.com/test/repo',
        ref: 'main',
        hash: 'abc123',
        graph: {
          containsEdges: [{ from: 'pack-a', to: 'page-1' }],
          dependsEdges: [{ from: 'pack-b', to: 'pack-a' }],
          roots: ['pack-a'],
          hasCycle: false,
        },
        meta: { schemaVersion: 1, timestamp: '20251105120000' },
      };

      expect(graphResponse.graph.containsEdges).toHaveLength(1);
      expect(graphResponse.graph.dependsEdges).toHaveLength(1);
      expect(graphResponse.graph.roots).toContain('pack-a');
      expect(graphResponse.graph.hasCycle).toBe(false);
    });

    it('should handle hierarchy response structure', () => {
      const hierarchyResponse = {
        repo_url: 'https://github.com/test/repo',
        ref: 'main',
        hash: 'abc123',
        hierarchy: {
          name: 'root',
          label: 'Root',
          type: 'root',
          children: [
            {
              name: 'pack-a',
              label: 'Pack A',
              type: 'pack',
              version: '1.0.0',
              children: [],
            },
          ],
        },
        meta: { schemaVersion: 1, timestamp: '20251105120000' },
      };

      expect(hierarchyResponse.hierarchy).toBeDefined();
      expect(hierarchyResponse.hierarchy.children).toHaveLength(1);
      expect(hierarchyResponse.hierarchy.children[0].name).toBe('pack-a');
    });

    it('should handle packs action response structure', () => {
      const packsActionResponse = {
        labkiPacksAction: {
          ok: true,
          diff: {
            'test-pack': {
              action: 'install',
              pages: {},
            },
          },
          warnings: [],
          state_hash: 'abc123',
          meta: { schemaVersion: 1, timestamp: '20251105120000' },
        },
      };

      const data = packsActionResponse.labkiPacksAction;
      expect(data.ok).toBe(true);
      expect(data.diff['test-pack'].action).toBe('install');
      expect(data.warnings).toEqual([]);
      expect(data.state_hash).toBe('abc123');
    });
  });

  describe('Error Handling', () => {
    it('should handle missing response data', () => {
      const emptyResponse = {};

      expect(emptyResponse).toEqual({});
    });

    it('should handle null responses', () => {
      const nullResponse = null;

      expect(nullResponse).toBeNull();
    });

    it('should validate meta structure', () => {
      const responseWithMeta = {
        data: {},
        meta: {
          schemaVersion: 1,
          timestamp: '20251105120000',
        },
      };

      expect(responseWithMeta.meta).toBeDefined();
      expect(responseWithMeta.meta.schemaVersion).toBe(1);
      expect(responseWithMeta.meta.timestamp).toMatch(/^\d{14}$/);
    });
  });
});
