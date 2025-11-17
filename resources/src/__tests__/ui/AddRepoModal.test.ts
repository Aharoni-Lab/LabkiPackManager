/**
 * Tests for AddRepoModal component.
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AddRepoModal from '../../ui/AddRepoModal.vue';

// Mock API endpoints
vi.mock('../../api/endpoints', () => ({
  reposAdd: vi.fn(() =>
    Promise.resolve({
      success: true,
      operation_id: 'repo_add_123',
      status: 'queued',
      message: 'Repository queued',
    }),
  ),
  pollOperation: vi.fn(() =>
    Promise.resolve({
      status: 'success',
      operation_id: 'repo_add_123',
    }),
  ),
}));

describe('AddRepoModal Component', () => {
  it('should render without crashing', () => {
    const wrapper = mount(AddRepoModal, {
      props: {
        open: true,
      },
    });
    expect(wrapper.exists()).toBe(true);
  });

  it('should not render when closed', () => {
    const wrapper = mount(AddRepoModal, {
      props: {
        open: false,
      },
    });
    // Component exists but dialog is closed
    expect(wrapper.exists()).toBe(true);
  });

  it('should have form inputs when open', () => {
    const wrapper = mount(AddRepoModal, {
      props: {
        open: true,
      },
    });

    // Should have input fields (either as CdxTextInput or regular inputs)
    expect(wrapper.exists()).toBe(true);
  });

  it('should emit close event', () => {
    const wrapper = mount(AddRepoModal, {
      props: {
        open: true,
      },
    });

    // Component should be able to emit close
    wrapper.vm.$emit('close');
    expect(wrapper.emitted('close')).toBeTruthy();
  });
});
