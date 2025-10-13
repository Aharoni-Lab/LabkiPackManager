import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import LpmTree from '../src/ui/tree.vue';

vi.useFakeTimers();

function makeHierarchy() {
  return {
    tree: [
      { type: 'pack', id: 'top chain pack' },
      { type: 'pack', id: 'standalone pack' },
    ],
    nodes: {
      'pack:top chain pack': {
        type: 'pack', id: 'pack:top chain pack', version: '1.0.0',
        pages: ['chain top page', 'NS:TopPage'], depends_on: ['mid chain pack'],
      },
      'pack:mid chain pack': {
        type: 'pack', id: 'pack:mid chain pack', version: '1.0.0',
        pages: ['chain mid page'], depends_on: ['base chain pack']
      },
      'pack:base chain pack': {
        type: 'pack', id: 'pack:base chain pack', version: '1.0.0',
        pages: ['chain base page']
      },
      'pack:standalone pack': {
        type: 'pack', id: 'pack:standalone pack', version: '1.0.0',
        pages: ['standalone page']
      },
    }
  };
}

describe('LpmTree', () => {
  const global = { stubs: { 'cdx-checkbox': true, 'cdx-text-input': true } } as any;
  let props: any;

  beforeEach(() => {
    props = {
      data: { hierarchy: makeHierarchy() },
      selectedPacks: {},
      prefixes: {},
      renames: {},
      checkTitleExists: null
    };
  });

  afterEach(() => {
    vi.clearAllTimers();
    vi.restoreAllMocks();
  });

  it('renders nested packs and pages', async () => {
    const w = mount(LpmTree as any, { props, global });
    const text = w.text();
    expect(text).toContain('top chain pack');
    expect(text).toContain('standalone pack');
    expect(text).toContain('mid chain pack');
    expect(text).toContain('base chain pack');
    expect(text).toContain('chain top page');
    expect(text).toContain('chain mid page');
    expect(text).toContain('chain base page');
    expect(text).toContain('standalone page');
  });

  it('default-expanded pack nodes expose expanded map entries', async () => {
    const w = mount(LpmTree as any, { props, global });
    const vm: any = w.vm;
    // every rendered pack (top + children) should have an expanded flag set on creation
    expect(Object.keys(vm.expanded).length).toBeGreaterThan(0);
    // A concrete one:
    expect(vm.expanded['pack:top chain pack']).toBe(true);
  });

  it('computes final titles with namespace, prefix, and rename', async () => {
    const w = mount(LpmTree as any, { props, global });
    const vm: any = w.vm;
    // No prefix/rename -> original
    expect(vm.finalPageTitle('top chain pack', 'chain top page')).toBe('chain top page');

    // Prefix only
    await w.setProps({ prefixes: { 'top chain pack': 'P-' } });
    expect(vm.finalPageTitle('top chain pack', 'chain top page')).toBe('P-chain top page');

    // Rename only (no prefix)
    await w.setProps({ prefixes: {}, renames: { 'top chain pack::chain top page': 'NewName' } });
    expect(vm.finalPageTitle('top chain pack', 'chain top page')).toBe('NewName');

    // Namespace preserved, prefix applied before rename/base
    await w.setProps({
      prefixes: { 'top chain pack': 'Pre-' },
      renames: { 'top chain pack::NS:TopPage': 'Renamed' }
    });
    expect(vm.finalPageTitle('top chain pack', 'NS:TopPage')).toBe('NS:Pre-Renamed');
  });

  it('updates collision status after rename/prefix with debounce', async () => {
    const checkTitleExists = vi.fn(async (t: string) => t.includes('collide'));
    const w = mount(LpmTree as any, { props: { ...props, checkTitleExists }, global });

    // Select a pack to enable checks
    await w.setProps({ selectedPacks: { 'top chain pack': true } });
    // scheduleCollisionRecheckForVisible uses default debounce (300)
    vi.advanceTimersByTime(350);

    // Trigger immediate rechecks for pages when updating prefix via child component
    const node = w.findComponent({ name: 'lpm-pack-node' });
    (node.vm as any).updatePrefix('collide-');
    // updatePrefix triggers debounceCheck(..., 0)
    vi.advanceTimersByTime(0);
    expect(checkTitleExists).toHaveBeenCalled();
  });

  it('deduplicates collision checks via async cache for identical titles', async () => {
    const checkTitleExists = vi.fn(async () => true);
    const w = mount(LpmTree as any, { props: { ...props, checkTitleExists }, global });
    const vm: any = w.vm;

    // Manually call debounceCheck twice with same title
    vm.debounceCheck('top chain pack::chain top page', 'SameTitle', 0);
    vm.debounceCheck('top chain pack::chain top page', 'SameTitle', 0);
    vi.advanceTimersByTime(0);

    // First call hits the API; cache used after it resolves prevents extra calls on same title
    await Promise.resolve();
    expect(checkTitleExists).toHaveBeenCalledTimes(1);

    // New title should cause another check
    vm.debounceCheck('top chain pack::chain top page', 'DifferentTitle', 0);
    vi.advanceTimersByTime(0);
    await Promise.resolve();
    expect(checkTitleExists).toHaveBeenCalledTimes(2);
  });

  it('rechecks collisions only for visible (selected) packs', async () => {
    const checkTitleExists = vi.fn(async () => false);
    const w = mount(LpmTree as any, { props: { ...props, checkTitleExists }, global });
    const vm: any = w.vm;

    await w.setProps({ selectedPacks: { 'top chain pack': true } });
    vm.scheduleCollisionRecheckForVisible();
    vi.advanceTimersByTime(300); // default debounce
    await Promise.resolve();
    // top chain pack has two pages in this fixture
    expect(checkTitleExists.mock.calls.length).toBe(2);

    // Now select the standalone pack; calls should increase by its page count (=1)
    await w.setProps({ selectedPacks: { 'top chain pack': true, 'standalone pack': true } });
    vm.scheduleCollisionRecheckForVisible();
    vi.advanceTimersByTime(300);
    await Promise.resolve();
    // total calls now 2 (before) + 1
    expect(checkTitleExists.mock.calls.length).toBe(3);
  });

  it('computes selection closure from explicit selection (depends_on chain)', async () => {
    const w = mount(LpmTree as any, { props, global });
    const vm: any = w.vm;

    vm.togglePackExplicit('top chain pack', true);
    await vm.$nextTick();

    // Capture the last emitted selectedPacks map
    const emitted = w.emitted()['update:selectedPacks'];
    expect(emitted).toBeTruthy();
    const last = emitted[emitted.length - 1][0];

    // Closure should include mid + base (depends_on), but not standalone
    expect(last['top chain pack']).toBe(true);
    expect(last['mid chain pack']).toBe(true);
    expect(last['base chain pack']).toBe(true);
    expect(last['standalone pack']).toBeUndefined();

    // Disabled packs should include auto-included deps (mid/base)
    expect(vm.disabledPacks['mid chain pack']).toBe(true);
    expect(vm.disabledPacks['base chain pack']).toBe(true);
    expect(vm.isPackDisabled('mid chain pack')).toBe(true);
  });


  it('forces selection of locked packs regardless of explicit selection', async () => {
    const data = { hierarchy: makeHierarchy() } as any;
    data.hierarchy.nodes['pack:standalone pack'].isLocked = true;

    const w = mount(LpmTree as any, { props: { ...props, data }, global });
    const emitted = w.emitted()['update:selectedPacks'];
    expect(emitted).toBeTruthy();
    const last = emitted[emitted.length - 1][0];

    // On create, locked pack should be selected automatically
    expect(last['standalone pack']).toBe(true);
  });

  it('shows pack state labels from installStatus/versions', async () => {
    const data = { hierarchy: makeHierarchy() } as any;
    data.hierarchy.nodes['pack:standalone pack'].installStatus = 'already-installed';
    data.hierarchy.nodes['pack:standalone pack'].installedVersion = '1.0.0';
    data.hierarchy.nodes['pack:top chain pack'].installStatus = 'safe-update';
    data.hierarchy.nodes['pack:top chain pack'].installedVersion = '0.9.0';
    data.hierarchy.nodes['pack:mid chain pack'].installStatus = 'incompatible-update';
    data.hierarchy.nodes['pack:mid chain pack'].installedVersion = '0.5.0';

    const w = mount(LpmTree as any, { props: { ...props, data }, global });
    const text = w.text();
    expect(text).toMatch(/Already imported \(v1\.0\.0\)/);
    expect(text).toMatch(/Update:\s+0\.9\.0 → 1\.0\.0/);
    expect(text).toMatch(/Major version change:\s+0\.5\.0 → 1\.0\.0/);
  });

  it('pack checkbox respects disabled/locked state', async () => {
    const data = { hierarchy: makeHierarchy() } as any;
    data.hierarchy.nodes['pack:standalone pack'].isLocked = true;

    const w = mount(LpmTree as any, { props: { ...props, data }, global });
    const node = w.findAllComponents({ name: 'lpm-pack-node' })
                  .find(c => (c.vm as any).packName === 'standalone pack')!;
    // We stubbed checkbox, but component receives :disabled truthy
    expect((node.vm as any).flatPack.isLocked).toBe(true);
  });

  it('toggle on caret flips expansion state per pack id', async () => {
    const w = mount(LpmTree as any, { props, global });
    const top = w.findAllComponents({ name: 'lpm-pack-node' })
                 .find(c => (c.vm as any).packName === 'top chain pack')!;
    const vmNode: any = top.vm;
    expect(vmNode.isOpen).toBe(true);
    vmNode.toggle();
    await top.vm.$nextTick();
    expect(vmNode.isOpen).toBe(false);
  });

  it('provides stable sanitized label ids for a11y', async () => {
    const w = mount(LpmTree as any, { props, global });
    const node = w.findAllComponents({ name: 'lpm-pack-node' })
                  .find(c => (c.vm as any).packName === 'top chain pack')!;
    const labelId = (node.vm as any).packLabelId;
    // only allowed chars [A-Za-z0-9_-]
    expect(/^[A-Za-z0-9_-]+$/.test(labelId)).toBe(true);
  });
});
