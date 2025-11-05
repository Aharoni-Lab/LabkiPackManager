/**
 * Tests for the reactive store.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { store } from '../../state/store';

describe('Store', () => {
  beforeEach(() => {
    // Reset store to initial state
    store.repoUrl = '';
    store.ref = '';
    store.mermaidSrc = '';
    store.hierarchy = null;
    store.packs = {};
    store.stateHash = '';
    store.warnings = [];
    store.busy = false;
    store.selectedRepo = null;
    store.repos = [];
  });

  describe('Initial State', () => {
    it('should have empty repoUrl', () => {
      expect(store.repoUrl).toBe('');
    });

    it('should have empty ref', () => {
      expect(store.ref).toBe('');
    });

    it('should have empty mermaidSrc', () => {
      expect(store.mermaidSrc).toBe('');
    });

    it('should have null hierarchy', () => {
      expect(store.hierarchy).toBeNull();
    });

    it('should have empty packs object', () => {
      expect(store.packs).toEqual({});
    });

    it('should have empty stateHash', () => {
      expect(store.stateHash).toBe('');
    });

    it('should have empty warnings array', () => {
      expect(store.warnings).toEqual([]);
    });

    it('should have busy as false', () => {
      expect(store.busy).toBe(false);
    });

    it('should have null selectedRepo', () => {
      expect(store.selectedRepo).toBeNull();
    });

    it('should have empty repos array', () => {
      expect(store.repos).toEqual([]);
    });
  });

  describe('State Mutations', () => {
    it('should allow updating repoUrl', () => {
      store.repoUrl = 'https://github.com/test/repo';
      expect(store.repoUrl).toBe('https://github.com/test/repo');
    });

    it('should allow updating ref', () => {
      store.ref = 'main';
      expect(store.ref).toBe('main');
    });

    it('should allow updating busy state', () => {
      store.busy = true;
      expect(store.busy).toBe(true);
    });

    it('should allow adding warnings', () => {
      store.warnings.push('Test warning');
      expect(store.warnings).toContain('Test warning');
      expect(store.warnings).toHaveLength(1);
    });

    it('should allow setting repos', () => {
      const testRepos = [
        { repo_id: 1, url: 'https://github.com/test/repo1', default_ref: 'main', refs: [], ref_count: 0 },
        { repo_id: 2, url: 'https://github.com/test/repo2', default_ref: 'main', refs: [], ref_count: 0 },
      ];
      store.repos = testRepos;
      expect(store.repos).toHaveLength(2);
      expect(store.repos[0].url).toBe('https://github.com/test/repo1');
    });
  });
});

