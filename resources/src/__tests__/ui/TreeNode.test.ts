/**
 * Tests for TreeNode component.
 */

import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import TreeNode from '../../ui/TreeNode.vue';

describe('TreeNode Component', () => {
  const mockNode = {
    name: 'test-pack',
    label: 'Test Pack', // TreeNode uses 'label' not 'display_name'
    version: '1.0.0',
    type: 'pack',
    children: [],
  };

  it('should render without crashing', () => {
    const wrapper = mount(TreeNode, {
      props: {
        node: mockNode,
        depth: 0,
      },
    });
    expect(wrapper.exists()).toBe(true);
  });

  it('should display pack name', () => {
    const wrapper = mount(TreeNode, {
      props: {
        node: mockNode,
        depth: 0,
      },
    });
    expect(wrapper.text()).toContain('Test Pack');
  });

  it('should display version', () => {
    const wrapper = mount(TreeNode, {
      props: {
        node: mockNode,
        depth: 0,
      },
    });
    expect(wrapper.text()).toContain('1.0.0');
  });

  it('should handle depth prop', () => {
    const wrapper = mount(TreeNode, {
      props: {
        node: mockNode,
        depth: 2,
      },
    });
    expect(wrapper.exists()).toBe(true);
  });

  it('should render children recursively', () => {
    const nodeWithChildren = {
      ...mockNode,
      children: [
        {
          name: 'child-pack',
          label: 'Child Pack',
          version: '1.0.0',
          type: 'pack',
          children: [],
        },
      ],
    };

    const wrapper = mount(TreeNode, {
      props: {
        node: nodeWithChildren,
        depth: 0,
      },
    });

    expect(wrapper.text()).toContain('Child Pack');
  });
});
