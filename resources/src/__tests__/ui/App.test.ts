/**
 * Tests for the main App component.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import App from '../../ui/App.vue';
import { store } from '../../state/store';

describe('App Component', () => {
  beforeEach(() => {
    // Reset store
    store.repoUrl = '';
    store.ref = '';
    store.busy = false;
    store.hierarchy = null;
  });

  it('should render without crashing', () => {
    const wrapper = mount(App, {
      global: {
        stubs: {
          'repo-ref-selector': true,
          'mermaid-graph': true,
          'hierarchy-tree': true,
          'details-panel': true,
        },
      },
    });
    expect(wrapper.exists()).toBe(true);
  });

  it('should render the app header', () => {
    const wrapper = mount(App, {
      global: {
        stubs: {
          'repo-ref-selector': true,
          'mermaid-graph': true,
          'hierarchy-tree': true,
          'details-panel': true,
        },
      },
    });
    expect(wrapper.find('.app-header').exists()).toBe(true);
    expect(wrapper.find('h1').exists()).toBe(true);
  });

  it('should show loading overlay when busy', () => {
    store.busy = true;
    const wrapper = mount(App, {
      global: {
        stubs: {
          'repo-ref-selector': true,
          'mermaid-graph': true,
          'hierarchy-tree': true,
          'details-panel': true,
        },
      },
    });
    expect(wrapper.find('.loading-overlay').exists()).toBe(true);
    expect(wrapper.find('.loading-spinner').exists()).toBe(true);
  });

  it('should hide loading overlay when not busy', () => {
    store.busy = false;
    const wrapper = mount(App, {
      global: {
        stubs: {
          'repo-ref-selector': true,
          'mermaid-graph': true,
          'hierarchy-tree': true,
          'details-panel': true,
        },
      },
    });
    expect(wrapper.find('.loading-overlay').exists()).toBe(false);
  });
});
