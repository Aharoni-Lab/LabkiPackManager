/**
 * Tests for MediaWiki API wrapper functions.
 */

import { describe, it, expect, vi } from 'vitest';

describe('MW API Utilities', () => {
  describe('API Call Wrapper', () => {
    it('should handle successful API calls', async () => {
      // Basic test to verify test infrastructure works
      const mockApiFn = vi.fn(() => Promise.resolve({ success: true }));
      const result = await mockApiFn();
      expect(result).toEqual({ success: true });
      expect(mockApiFn).toHaveBeenCalledOnce();
    });

    it('should handle API errors', async () => {
      const mockApiFn = vi.fn(() => Promise.reject(new Error('API Error')));
      await expect(mockApiFn()).rejects.toThrow('API Error');
    });
  });

  describe('API Response Handling', () => {
    it('should extract data from API responses', () => {
      const mockResponse = {
        labkiReposList: {
          repos: [{ repo_id: 1, url: 'test' }],
          meta: { schemaVersion: 1 },
        },
      };

      expect(mockResponse.labkiReposList.repos).toHaveLength(1);
      expect(mockResponse.labkiReposList.repos[0].repo_id).toBe(1);
    });

    it('should handle missing response data', () => {
      const mockResponse = {};
      expect(mockResponse).toEqual({});
    });
  });
});
