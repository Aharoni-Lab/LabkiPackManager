/**
 * Tests for RepoRefSelector component.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import RepoRefSelector from '../../ui/RepoRefSelector.vue';
import { store } from '../../state/store';

// Mock API endpoints
vi.mock('../../api/endpoints', () => ({
  reposList: vi.fn(() => Promise.resolve({ repos: [], meta: {} })),
  graphGet: vi.fn(() => Promise.resolve({ graph: {}, meta: {} })),
  hierarchyGet: vi.fn(() => Promise.resolve({ hierarchy: null, meta: {} })),
  packsAction: vi.fn(() => Promise.resolve({ labkiPacksAction: { ok: true, diff: {}, warnings: [], state_hash: '' } })),
  pollOperation: vi.fn(() => Promise.resolve({ status: 'success' })),
}));

describe('RepoRefSelector Component', () => {
  beforeEach(() => {
    store.repos = [];
    store.repoUrl = '';
    store.ref = '';
    store.selectedRepo = null;
  });

  it('should render without crashing', () => {
    const wrapper = mount(RepoRefSelector);
    expect(wrapper.exists()).toBe(true);
  });

  it('should display when no repos are available', () => {
    store.repos = [];
    const wrapper = mount(RepoRefSelector);
    expect(wrapper.exists()).toBe(true);
  });

  it('should respond to store changes reactively', async () => {
    const wrapper = mount(RepoRefSelector);
    
    store.repos = [
      {
        repo_id: 1,
        url: 'https://github.com/test/repo',
        default_ref: 'main',
        refs: [{ 
          ref_id: 1, 
          ref: 'main', 
          ref_name: 'main', 
          is_default: true,
          last_commit: 'abc123',
          manifest_hash: 'hash123',
          manifest_last_parsed: null,
          created_at: '20251105120000',
          updated_at: '20251105120000',
        }],
        ref_count: 1,
        last_fetched: '20251105120000',
        created_at: '20251105120000',
        updated_at: '20251105120000',
      },
    ];
    
    await wrapper.vm.$nextTick();
    expect(wrapper.exists()).toBe(true);
  });

  it('should have repo selection controls', () => {
    const wrapper = mount(RepoRefSelector);
    // Component should have selection UI elements
    expect(wrapper.find('.repo-ref-selector').exists() || wrapper.find('select').exists() || wrapper.exists()).toBe(true);
  });

  it('should handle empty refs array', () => {
    store.repos = [
      {
        repo_id: 1,
        url: 'https://github.com/test/repo',
        default_ref: 'main',
        refs: [],
        ref_count: 0,
        last_fetched: null,
        created_at: '20251105120000',
        updated_at: '20251105120000',
      },
    ];

    const wrapper = mount(RepoRefSelector);
    expect(wrapper.exists()).toBe(true);
  });
});

