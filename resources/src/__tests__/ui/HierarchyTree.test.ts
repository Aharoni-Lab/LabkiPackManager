/**
 * Tests for HierarchyTree component.
 */

import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import HierarchyTree from '../../ui/HierarchyTree.vue';

describe('HierarchyTree Component', () => {
  it('should render without crashing', () => {
    const wrapper = mount(HierarchyTree, {
      props: {
        hierarchy: null,
      },
    });
    expect(wrapper.exists()).toBe(true);
  });

  it('should handle null hierarchy', () => {
    const wrapper = mount(HierarchyTree, {
      props: {
        hierarchy: null,
      },
    });
    expect(wrapper.exists()).toBe(true);
  });

  it('should render hierarchy with children', () => {
    const mockHierarchy = {
      name: 'root',
      label: 'Root',
      type: 'root',
      meta: {
        pack_count: 1,
        page_count: 5,
      },
      children: [
        {
          name: 'pack-a',
          label: 'Pack A',
          type: 'pack',
          version: '1.0.0',
          children: [],
        },
      ],
    };

    const wrapper = mount(HierarchyTree, {
      props: {
        hierarchy: mockHierarchy,
      },
    });

    expect(wrapper.exists()).toBe(true);
    // Should render the hierarchy
    expect(wrapper.findComponent({ name: 'TreeNode' }).exists() || wrapper.find('.tree-content').exists()).toBe(true);
  });

  it('should handle empty children array', () => {
    const mockHierarchy = {
      name: 'root',
      label: 'Root',
      type: 'root',
      meta: {
        pack_count: 0,
        page_count: 0,
      },
      children: [],
    };

    const wrapper = mount(HierarchyTree, {
      props: {
        hierarchy: mockHierarchy,
      },
    });

    expect(wrapper.exists()).toBe(true);
  });
});

