/**
 * Tests for TypeScript types and interfaces.
 * 
 * These tests verify that our type definitions work correctly.
 */

import { describe, it, expect } from 'vitest';
import type { Repo, PacksState } from '../../state/types';

describe('Type Definitions', () => {
  describe('Repo Type', () => {
    it('should allow valid repo objects', () => {
      const repo: Repo = {
        repo_id: 1,
        url: 'https://github.com/test/repo',
        default_ref: 'main',
        refs: [],
        ref_count: 0,
      };

      expect(repo.repo_id).toBe(1);
      expect(repo.url).toBe('https://github.com/test/repo');
      expect(repo.default_ref).toBe('main');
    });
  });

  describe('PacksState Type', () => {
    it('should allow valid packs state objects', () => {
      const packsState: PacksState = {
        'test-pack': {
          action: 'install',
          prefix: 'TestPack',
          target_version: '1.0.0',
          current_version: null,
          installed: false,
          auto_selected_reason: null,
          pages: {},
        },
      };

      expect(packsState['test-pack'].action).toBe('install');
      expect(packsState['test-pack'].target_version).toBe('1.0.0');
    });
  });
});

