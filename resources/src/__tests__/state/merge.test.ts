/**
 * Tests for state diff merge logic.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { mergeDiff } from '../../state/merge';
import type { PacksState } from '../../state/types';

describe('Merge Utility', () => {
  describe('mergeDiff', () => {
    it('should merge new pack into empty state', () => {
      const target: PacksState = {};
      const diff: PacksState = {
        'new-pack': {
          action: 'install',
          prefix: 'NewPack',
          target_version: '1.0.0',
          current_version: null,
          installed: false,
          auto_selected_reason: null,
          pages: {},
        },
      };

      mergeDiff(target, diff);

      expect(target['new-pack']).toBeDefined();
      expect(target['new-pack'].action).toBe('install');
      expect(target['new-pack'].target_version).toBe('1.0.0');
    });

    it('should update existing pack with changes', () => {
      const target: PacksState = {
        'existing-pack': {
          action: 'unchanged',
          prefix: 'OldPrefix',
          target_version: '1.0.0',
          current_version: '1.0.0',
          installed: true,
          auto_selected_reason: null,
          pages: {},
        },
      };

      const diff: PacksState = {
        'existing-pack': {
          action: 'update',
          prefix: 'NewPrefix',
        } as any,
      };

      mergeDiff(target, diff);

      expect(target['existing-pack'].action).toBe('update');
      expect(target['existing-pack'].prefix).toBe('NewPrefix');
      // Unchanged fields should remain
      expect(target['existing-pack'].target_version).toBe('1.0.0');
      expect(target['existing-pack'].installed).toBe(true);
    });

    it('should merge page changes', () => {
      const target: PacksState = {
        'test-pack': {
          action: 'unchanged',
          prefix: 'Test',
          pages: {
            'page-1': {
              final_title: 'Test/Page1',
              original_title: 'Page1',
              installed: false,
            },
          },
        } as any,
      };

      const diff: PacksState = {
        'test-pack': {
          pages: {
            'page-1': {
              final_title: 'Test/RenamedPage1',
            } as any,
            'page-2': {
              final_title: 'Test/Page2',
              original_title: 'Page2',
              installed: false,
            } as any,
          },
        } as any,
      };

      mergeDiff(target, diff);

      expect(target['test-pack'].pages['page-1'].final_title).toBe('Test/RenamedPage1');
      expect(target['test-pack'].pages['page-1'].original_title).toBe('Page1');
      expect(target['test-pack'].pages['page-2']).toBeDefined();
      expect(target['test-pack'].pages['page-2'].final_title).toBe('Test/Page2');
    });

    it('should handle multiple pack changes in one diff', () => {
      const target: PacksState = {
        'pack-a': { action: 'unchanged' } as any,
      };

      const diff: PacksState = {
        'pack-a': { action: 'install' } as any,
        'pack-b': { action: 'install' } as any,
        'pack-c': { action: 'remove' } as any,
      };

      mergeDiff(target, diff);

      expect(target['pack-a'].action).toBe('install');
      expect(target['pack-b'].action).toBe('install');
      expect(target['pack-c'].action).toBe('remove');
    });

    it('should preserve pages not in diff', () => {
      const target: PacksState = {
        'test-pack': {
          action: 'unchanged',
          pages: {
            'page-1': { final_title: 'Page1' } as any,
            'page-2': { final_title: 'Page2' } as any,
          },
        } as any,
      };

      const diff: PacksState = {
        'test-pack': {
          action: 'install',
          pages: {
            'page-1': { final_title: 'UpdatedPage1' } as any,
          },
        } as any,
      };

      mergeDiff(target, diff);

      expect(target['test-pack'].pages['page-1'].final_title).toBe('UpdatedPage1');
      expect(target['test-pack'].pages['page-2'].final_title).toBe('Page2');
    });

    it('should handle empty diff gracefully', () => {
      const target: PacksState = {
        'pack-a': { action: 'unchanged' } as any,
      };

      const diff: PacksState = {};

      mergeDiff(target, diff);

      expect(target['pack-a'].action).toBe('unchanged');
    });
  });
});
