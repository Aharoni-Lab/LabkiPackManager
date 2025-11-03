<template>
  <div class="labki-tree">
    <div
      class="tree-node"
      :data-type="node.type"
      :data-action="packState?.action || 'unchanged'"
      :style="{ marginLeft: `${depth * 1.5}rem` }"
    >

      <div class="node-row" :style="{ '--indent': depth }">
        <!-- Toggle -->
        <button
          v-if="hasChildren"
          class="toggle"
          :aria-expanded="expanded.toString()"
          :aria-label="expanded ? $t('labkipackmanager-collapse') : $t('labkipackmanager-expand')"
          @click="toggleExpanded"
        >
          {{ expanded ? '▼' : '▶' }}
        </button>
        <span v-else class="toggle-spacer" aria-hidden="true"></span>

        <!-- Icon -->
        <span class="node-icon" aria-hidden="true">
          {{ node.type === 'pack' ? '📦' : '📄' }}
        </span>

        <!-- Main -->
        <div class="main">
          <div class="title-line">
            <strong class="label" :title="node.label">{{ node.label }}</strong>

            <span v-if="node.version" class="badge version">v{{ node.version }}</span>

            <span
              v-if="packState && packState.action && packState.action !== 'unchanged'"
              class="badge"
              :class="packState.auto_selected_reason ? 'auto' : 'manual'"
              :title="packState.auto_selected_reason || ''"
            >
              {{
                packState.auto_selected_reason
                  ? $t('labkipackmanager-auto-selected')
                  : $t('labkipackmanager-manually-selected')
              }}
            </span>

            <span v-if="canUpdate" class="badge update">
              {{ $t('labkipackmanager-update-available') }}
            </span>
          </div>

          <div v-if="node.description" class="desc">
            {{ node.description }}
          </div>

          <div v-if="node.depends_on?.length" class="depends">
            <small
              >{{ $t('labkipackmanager-depends-on') }}:
              {{ node.depends_on.join(', ') }}</small
            >
          </div>

          <!-- Pack prefix editor -->
          <div
            v-if="node.type === 'pack' && showPackEditor"
            class="inline-editor pack"
            :class="{ readonly: !isPackEditable }"
          >
            <label class="inline-label">{{
              $t('labkipackmanager-pack-prefix')
            }}</label>
            <input
              class="input"
              type="text"
              :value="prefixInputValue"
              :placeholder="
                $t('labkipackmanager-prefix-placeholder') ||
                'MyNamespace/MyPack'
              "
              :disabled="!isPackEditable"
              :readonly="!isPackEditable"
              @input="onPrefixChange"
            />
          </div>

          <!-- Page title editor -->
          <div
            v-if="node.type === 'page' && showPageEditor"
            class="inline-editor page"
            :class="{ readonly: !isPageEditable }"
          >
            <label class="inline-label">{{
              $t('labkipackmanager-page-title')
            }}</label>
            <div class="page-editor">
              <span
                v-if="displayPrefix"
                class="prefix-chip"
                :title="displayPrefixWithSlash"
              >
                {{ displayPrefixWithSlash }}
              </span>
              <input
                class="input page-title"
                :class="{ 'has-collision': pageHasCollision }"
                type="text"
                :value="pageEditableTitle"
                :placeholder="
                  $t('labkipackmanager-page-title-placeholder') || 'PageTitle'
                "
                :disabled="!isPageEditable"
                :readonly="!isPageEditable"
                @input="onPageTitleChange"
                :aria-invalid="pageHasCollision ? 'true' : 'false'"
                :aria-describedby="pageHasCollision ? collisionId : undefined"
              />
              <span
                v-if="pageHasCollision"
                class="collision"
                :id="collisionId"
                :title="collisionTooltip"
                aria-live="polite"
                >⚠️</span
              >
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="actions" v-if="node.type === 'pack'">
          <div
            class="action-item"
            v-if="packState && packState.current_version === null"
          >
            <cdx-button
              action="progressive"
              :class="{ active: packState.action === 'install' }"
              @click="toggleInstall"
            >
              {{ $t('labkipackmanager-select') }}
            </cdx-button>
          </div>

          <div class="action-item" v-if="canUpdate">
            <cdx-button
              :class="{ active: packState?.action === 'update' }"
              @click="toggleUpdate"
            >
              {{ $t('labkipackmanager-update') }}
            </cdx-button>
          </div>

          <div
            class="action-item"
            v-if="packState && packState.current_version !== null"
          >
            <cdx-button
              action="destructive"
              :class="{ active: packState.action === 'remove' }"
              @click="toggleRemove"
            >
              {{ $t('labkipackmanager-remove') }}
            </cdx-button>
          </div>
        </div>
      </div>

      <transition-group
        name="children"
        tag="div"
        class="children"
        v-if="expanded && hasChildren"
      >
        <tree-node
          v-for="child in sortedChildren"
          :key="child.id"
          :node="child"
          :depth="depth + 1"
          :parent-pack-name="nodePackName"
          @set-pack-action="$emit('set-pack-action', $event)"
        />
      </transition-group>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onBeforeUnmount, watch } from 'vue'
import { CdxButton } from '@wikimedia/codex'
import { store } from '../state/store'
import { packsAction } from '../api/endpoints'
import { mergeDiff } from '../state/merge'

const props = defineProps({
  node: { type: Object, required: true },
  depth: { type: Number, required: true },
  parentPackName: { type: String, default: null },
})
const emit = defineEmits(['set-pack-action'])

const expanded = ref(false)
let prefixTimer = null
let pageTimer = null

const hasChildren = computed(
  () => !!(props.node.children && props.node.children.length)
)
const packState = computed(() =>
  props.node.type === 'pack' ? store.packs[props.node.label] || null : null
)
const isPackSelected = computed(() => {
  // Pack is "selected" if it has any action set (install, update, remove)
  const action = packState.value?.action
  return action && action !== 'unchanged'
})
const isPackEditable = computed(() => {
  // Only allow editing prefix for packs being newly installed
  const action = packState.value?.action
  const installed = packState.value?.installed
  return action === 'install' && !installed
})
const showPackEditor = computed(() => {
  // Show editor if pack has install/update action OR is already installed
  const action = packState.value?.action
  const installed = packState.value?.installed
  return (action === 'install' || action === 'update') || installed
})
const prefixInputValue = computed(() => packState.value?.prefix || '')
const nodePackName = computed(() =>
  props.node.type === 'pack' ? props.node.label : null
)
const parentName = computed(() =>
  props.node.type === 'page' ? props.parentPackName || null : null
)
const parentPackState = computed(() =>
  parentName.value ? store.packs[parentName.value] || null : null
)
const pageState = computed(() =>
  parentPackState.value?.pages
    ? parentPackState.value.pages[props.node.label] || null
    : null
)
const isPageParentSelected = computed(() => {
  // Parent is "selected" if it has install or update action set
  const action = parentPackState.value?.action
  return action === 'install' || action === 'update'
})
const isPageEditable = computed(() => {
  // Only allow editing page title for pages being newly installed
  const action = parentPackState.value?.action
  const installed = pageState.value?.installed
  return action === 'install' && !installed
})
const showPageEditor = computed(() => {
  // Show editor if parent has install/update action OR page is already installed
  const action = parentPackState.value?.action
  const installed = pageState.value?.installed
  return (action === 'install' || action === 'update') || installed
})
const displayPrefix = computed(() => parentPackState.value?.prefix || '')
const displayPrefixWithSlash = computed(() =>
  displayPrefix.value
    ? displayPrefix.value.endsWith('/')
      ? displayPrefix.value
      : displayPrefix.value + '/'
    : ''
)
const pageEditableTitle = computed(() => {
  const full = pageState.value?.final_title || ''
  const pref = displayPrefixWithSlash.value
  return pref && full.startsWith(pref) ? full.slice(pref.length) : full
})
const pageHasCollision = computed(() => {
  const full = pageState.value?.final_title
  if (!full || !parentName.value) return false
  return store.warnings.some(
    (w) => w.includes(full) && w.includes(parentName.value)
  )
})
const collisionTooltip = computed(() => {
  const full = pageState.value?.final_title
  if (!full || !parentName.value) return ''
  return store.warnings
    .filter((w) => w.includes(full) && w.includes(parentName.value))
    .join('\n')
})
const collisionId = computed(
  () => `collision-${parentName.value || 'none'}-${props.node.label}`
)
const canUpdate = computed(() => {
  const ps = packState.value
  if (!ps) return false
  if (ps.current_version === null) return false
  if (!ps.target_version) return false
  return ps.target_version > ps.current_version
})

/* Debug watcher */
if (props.node.type === 'pack') {
  watch(() => packState.value?.action, (newAction, oldAction) => {
    console.log(`[TreeNode:${props.node.label}] packState.action changed: "${oldAction}" -> "${newAction}"`)
  })
}

/* auto-expand */
const subtreeHasAction = computed(() => {
  store.stateHash
  const check = (n) => {
    if (n.type === 'pack') {
      const s = store.packs[n.label]
      if (s && s.action && s.action !== 'unchanged') return true
    }
    if (!n.children) return false
    for (const c of n.children) if (check(c)) return true
    return false
  }
  return check(props.node)
})
expanded.value = props.depth < 2 || subtreeHasAction.value

function toggleExpanded() { expanded.value = !expanded.value }

function toggleAction(action) {
  // Toggle the action - if already set, clear it; otherwise set it
  const current = packState.value?.action
  const next = current === action ? 'unchanged' : action
  console.log(`[TreeNode:${props.node.label}] toggleAction: current="${current}", requested="${action}", next="${next}"`)
  console.log(`[TreeNode:${props.node.label}] packState:`, packState.value)
  emit('set-pack-action', { pack_name: props.node.label, action: next })
}

function toggleInstall() {
  // For uninstalled packs, just toggle install on/off
  toggleAction('install')
}

function toggleRemove() {
  // For installed packs, toggle remove on/off
  // If update is active, this will deactivate it and activate remove
  toggleAction('remove')
}

function toggleUpdate() {
  // For installed packs, toggle update on/off  
  // If remove is active, this will deactivate it and activate update
  toggleAction('update')
}

function onPrefixChange(e) {
  // Don't allow changes for installed packs
  if (!isPackEditable.value) return
  
  const val = e.target.value
  if (prefixTimer) clearTimeout(prefixTimer)
  prefixTimer = setTimeout(() => sendSetPackPrefixCommand(val), 400)
}
function onPageTitleChange(e) {
  // Don't allow changes for installed pages
  if (!isPageEditable.value) return
  
  const editable = e.target.value
  if (pageTimer) clearTimeout(pageTimer)
  pageTimer = setTimeout(() => {
    const newTitle = displayPrefixWithSlash.value
      ? displayPrefixWithSlash.value + editable
      : editable
    sendRenamePageCommand(newTitle)
  }, 400)
}

async function sendSetPackPrefixCommand(prefix) {
  if (store.busy) return
  try {
    store.busy = true
    const response = await packsAction({
      command: 'set_pack_prefix',
      repo_url: store.repoUrl,
      ref: store.ref,
      data: { pack_name: props.node.label, prefix },
    })
    mergeDiff(store.packs, response.diff)
    store.stateHash = response.state_hash
    store.warnings = response.warnings
  } catch (e) {
    console.error('set_pack_prefix failed:', e)
  } finally {
    store.busy = false
  }
}
async function sendRenamePageCommand(newTitle) {
  if (store.busy || !parentName.value) return
  try {
    store.busy = true
    const response = await packsAction({
      command: 'rename_page',
      repo_url: store.repoUrl,
      ref: store.ref,
      data: {
        pack_name: parentName.value,
        page_name: props.node.label,
        new_title: newTitle,
      },
    })
    mergeDiff(store.packs, response.diff)
    store.stateHash = response.state_hash
    store.warnings = response.warnings
  } catch (e) {
    console.error('rename_page failed:', e)
  } finally {
    store.busy = false
  }
}
onBeforeUnmount(() => {
  if (prefixTimer) clearTimeout(prefixTimer)
  if (pageTimer) clearTimeout(pageTimer)
})
function $t(k) { return mw.msg(k) }

const sortedChildren = computed(() => {
  if (!props.node.children) return []
  const arr = [...props.node.children]
  const ci = (s) => (s || '').toLocaleLowerCase()
  arr.sort((a, b) => {
    if (a.type === 'page' && b.type !== 'page') return -1
    if (a.type !== 'page' && b.type === 'page') return 1
    const la = ci(a.label)
    const lb = ci(b.label)
    if (la < lb) return -1
    if (la > lb) return 1
    return 0
  })
  return arr
})
</script>

<style scoped>

/* Indent nested node groups visually */
.labki-tree .children {
  margin-left: calc(1.5rem) !important;
  border-left: 1px solid #eaecf0 !important;
  padding-left: 0.75rem !important;
}

.labki-tree .tree-node[data-type="pack"] .node-row:hover { background: #f8f9fa; }
.labki-tree .tree-node[data-action="install"] .node-row { background: #e8f5e9; }
.labki-tree .tree-node[data-action="update"]  .node-row { background: #fff3e0; }
.labki-tree .tree-node[data-action="remove"]  .node-row { background: #ffebee; }

/* Pin grid cells */
.labki-tree .toggle, .labki-tree .toggle-spacer {
  background: none; border: none; cursor: pointer;
  padding: 2px 4px; font-size: 12px; color: #72777d;
  flex-shrink: 0;
}
.labki-tree .toggle:hover { color: #202122; }
.labki-tree .toggle-spacer { width: 16px; }
.labki-tree .node-icon { 
  font-size: 16px; line-height: 1.4; align-self: center;
  flex-shrink: 0;
}

/* Main content uses flex */
.labki-tree .main {
  flex: 1 !important;
  display: block !important;
  min-width: 0;
}

/* Title line: flex to keep on one line */
.labki-tree .title-line {
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
  flex-wrap: wrap !important;
  margin-bottom: 4px !important;
}

.labki-tree .label {
  margin: 0 !important;
  font-size: 1em !important;
  word-break: break-word !important;
}

/* Badges */
.labki-tree .badge {
  display: inline-block !important;
  font-size: 0.75em !important;
  padding: 2px 8px !important;
  border-radius: 3px !important;
  white-space: nowrap !important;
  font-weight: normal !important;
  flex-shrink: 0 !important;
}

.labki-tree .badge.manual { background: #eaf3ff !important; color: #36c !important; }
.labki-tree .badge.auto { background: #fef6e7 !important; color: #ac6600 !important; }
.labki-tree .badge.version { background: #f0f0f0 !important; color: #72777d !important; }
.labki-tree .badge.update { background: #fff3e0 !important; color: #ac6600 !important; }

.labki-tree .desc {
  font-size: 0.9em !important;
  color: #54595d !important;
  margin: 4px 0 !important;
}

.labki-tree .depends {
  font-size: 0.85em !important;
  color: #72777d !important;
  margin: 4px 0 !important;
}

.labki-tree .depends small {
  margin: 0 !important;
  display: block !important;
}

/* Inline editors */
.labki-tree .inline-editor {
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
  padding: 6px 8px !important;
  border-radius: 4px !important;
  background: #f8f9fa !important;
  margin-top: 6px !important;
}

.labki-tree .inline-editor.pack {
  background: #eaf3ff !important;
}

.labki-tree .inline-editor.readonly {
  background: #f0f0f0 !important;
  opacity: 0.8 !important;
}

.labki-tree .inline-label {
  font-weight: 600 !important;
  font-size: 0.9em !important;
  white-space: nowrap !important;
  margin: 0 !important;
  flex-shrink: 0 !important;
}

.labki-tree .inline-editor.pack .inline-label {
  color: #36c !important;
}

.labki-tree .inline-editor.page .inline-label {
  color: #54595d !important;
}

.labki-tree .input {
  flex: 1 !important;
  min-width: 150px !important;
  padding: 6px 8px !important;
  border: 1px solid #c8ccd1 !important;
  border-radius: 4px !important;
  font-size: 0.9em !important;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Verdana, sans-serif !important;
  background: white !important;
  color: #202122 !important;
}

.labki-tree .input:focus {
  outline: none !important;
  border-color: #36c !important;
  box-shadow: inset 0 0 0 1px #36c !important;
}

.labki-tree .input:disabled,
.labki-tree .input[readonly] {
  background: #f8f9fa !important;
  color: #72777d !important;
  cursor: not-allowed !important;
  border-color: #dcdfe2 !important;
}

.labki-tree .input.has-collision {
  border-color: #d33 !important;
  background-color: #fff5f5 !important;
}

.labki-tree .input.has-collision:focus {
  border-color: #d33 !important;
  box-shadow: inset 0 0 0 1px #d33 !important;
}

/* Page editor with prefix chip */
.labki-tree .page-editor {
  display: flex !important;
  align-items: center !important;
  gap: 4px !important;
  flex: 1 !important;
  min-width: 0 !important;
}

.labki-tree .prefix-chip {
  display: inline-block !important;
  padding: 6px 8px !important;
  background: #f0f0f0 !important;
  border: 1px solid #c8ccd1 !important;
  border-radius: 4px 0 0 4px !important;
  font-size: 0.9em !important;
  font-family: monospace !important;
  color: #54595d !important;
  white-space: nowrap !important;
  flex-shrink: 0 !important;
  border-right: 2px solid #36c !important;
}

.labki-tree .page-title {
  border-radius: 0 4px 4px 0 !important;
}

.labki-tree .collision {
  font-size: 1.1em !important;
  cursor: help !important;
  padding: 2px 4px !important;
  flex-shrink: 0 !important;
}

/* Actions column */
.labki-tree .actions {
  display: inline-flex !important;
  flex-wrap: wrap;
  align-items: center !important;
  gap: 6px !important;
  flex-shrink: 0 !important;
}

.labki-tree .action-item { display: inline-flex !important; }
.labki-tree .action-item :deep(.cdx-button) { display: inline-flex !important; white-space: nowrap; }

/* Highlights for active buttons */
.labki-tree :deep(.cdx-button.active) {
  outline: 2px solid #36c;
  box-shadow: inset 0 0 4px rgba(0, 0, 0, .15);
  font-weight: 600;
}

/* --- VISUAL INDENTATION GUIDES FOR NESTED NODES --- */
.labki-tree .children {
  position: relative;
  margin-left: 1.5rem !important;
  padding-left: 0.75rem !important;
}

.labki-tree .children::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  bottom: 0;
  width: 1px;
  background-color: #dcdfe2;
  opacity: 0.8;
}

/* Horizontal connector for each node */
.labki-tree .node-row {
  position: relative;
  z-index: 1;
}

.labki-tree .node-row::before {
  content: '';
  position: absolute;
  left: -0.75rem;
  top: 1.2rem;
  width: 0.75rem;
  height: 1px;
  background-color: #dcdfe2;
  opacity: 0.8;
}

</style>
