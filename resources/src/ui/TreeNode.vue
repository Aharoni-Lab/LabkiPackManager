<template>
  <!--
    ============================================================================
    TREE NODE LAYOUT - CARD-BASED NESTING WITH INLINE STYLES
    ============================================================================
    
    🎯 CRITICAL SUCCESS FACTORS - WHY THIS WORKS:
    
    1. **INLINE STYLES ARE REQUIRED** - CSS classes were NOT working due to:
       - MediaWiki/Codex CSS has extremely high specificity
       - External stylesheets were being overridden
       - Inline styles have highest specificity and can't be overridden
       - ✅ Use :style="" binding in template for all visual styling
       - ❌ DO NOT rely on CSS classes alone for layout/colors
    
    2. **CHILDREN MUST BE INSIDE PACK-CARD** - This creates true nesting:
       - The .pack-card div wraps the entire pack (row + meta + children)
       - Children render INSIDE their parent's card, not as siblings
       - This creates the "recursive card nesting" visual effect
       - Each nested pack gets its own card INSIDE the parent card
    
    3. **INDENTATION** - Applied via inline paddingLeft on .node-row:
       - Formula: `paddingLeft: ${depth * 24}px`
       - depth=0: 0px, depth=1: 24px, depth=2: 48px, etc.
       - Applied to .node-row, NOT the .pack-card wrapper
    
    4. **COLOR CODING** - Inline background colors based on state:
       - Installed: #e8f0f8 (blue)
       - Install action: #e8f5e9 (green)
       - Remove action: #ffebee (red)
       - Update action: #fff8e1 (yellow)
       - Default: #ffffff (white)
    
    5. **VISUAL HIERARCHY**:
       - Border: 2px solid creates clear card boundaries
       - Border-radius: 8px for rounded corners
       - Box-shadow: 0 2px 4px rgba() for depth
       - Padding: 12px inside cards for breathing room
       - Margin-bottom: 12px between cards
    
    ⚠️ MAINTENANCE NOTES:
    - If visual changes don't appear, check if inline styles are present
    - CSS classes are kept for semantic purposes but don't control layout
    - Any layout-critical styling MUST be in :style="" bindings
    - Test changes by checking if inline styles render in browser DevTools
    
    ============================================================================
  -->
  <div
    class="tree-node"
    :class="{ 'is-pack': node.type === 'pack', 'is-page': node.type === 'page' }"
    :data-type="node.type"
    :data-action="packState?.action || pageParentAction"
    :data-installed="node.type === 'pack' ? packState?.installed : pageState?.installed"
  >
      <!-- 
        PACK CARD WRAPPER - Creates rounded rectangle container
        ⚠️ INLINE STYLES REQUIRED: CSS classes don't work due to MW/Codex specificity
        - Border creates card boundary
        - Background color shows state: blue=installed, green=install, red=remove, yellow=update
        - Children render INSIDE this div for proper nesting
      -->
      <div 
        v-if="node.type === 'pack'" 
        class="pack-card" 
        :data-depth="depth"
        :style="{
          border: '2px solid #c8ccd1',           /* Card boundary */
          borderRadius: '8px',                   /* Rounded corners */
          padding: '10px',                       /* Breathing room inside card */
          marginBottom: '8px',                   /* Space between cards */
          background: packState?.installed ? '#e8f0f8' :        /* Blue = installed */
                      packState?.action === 'install' ? '#e8f5e9' :  /* Green = install */
                      packState?.action === 'remove' ? '#ffebee' :   /* Red = remove */
                      packState?.action === 'update' ? '#fff8e1' :   /* Yellow = update */
                      '#ffffff',                                      /* White = default */
          boxShadow: '0 2px 4px rgba(0,0,0,0.1)' /* Depth/elevation effect */
        }"
      >
        <!-- 
          TWO-COLUMN LAYOUT: Pack info on left, Pages on right
          ⚠️ Main content area splits into left (pack) and right (pages)
        -->
        <div class="pack-content-wrapper" :style="{
          display: 'flex',
          gap: '12px',
          alignItems: 'flex-start'
        }">
          <!-- LEFT COLUMN: Pack info and actions -->
          <div class="pack-info-column" :style="{
            flex: childPages.length > 0 ? '0 1 auto' : '1',
            minWidth: '0',
            display: 'flex',
            flexDirection: 'column',
            gap: '8px'
          }">
            <!-- Pack header row -->
            <div class="node-row" :style="{ 
              paddingLeft: `${depth * 24}px`,
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              flexWrap: 'nowrap'
            }">
              <!-- Toggle arrow -->
              <button
                v-if="childPacks.length > 0"
                class="toggle"
                :aria-expanded="expanded.toString()"
                :aria-label="expanded ? $t('labkipackmanager-collapse') : $t('labkipackmanager-expand')"
                @click="toggleExpanded"
              >
                {{ expanded ? '▼' : '▶' }}
              </button>
              <span v-else class="toggle-spacer"></span>

              <!-- Icon -->
              <span class="node-icon">📦</span>
              
              <!-- Name and badges -->
              <span class="name-section">
                <strong class="label" :title="node.label">{{ node.label }}</strong>
                
                <span v-if="node.version" class="badge version">v{{ node.version }}</span>
                
                <span
                  v-if="packState && packState.action && packState.action !== 'unchanged'"
                  class="badge"
                  :class="packState.auto_selected_reason ? 'auto' : 'manual'"
                  :title="packState.auto_selected_reason || ''"
                >
                  {{ packState.auto_selected_reason ? 'Auto' : 'Manual' }}
                </span>
                
                <span v-if="canUpdate" class="badge update">Update Available</span>
              </span>
            </div>
            
            <!-- Pack: Inline prefix editor (on second row) -->
            <div v-if="showPackEditor" :style="{ 
              paddingLeft: `${depth * 24 + 32}px`,
              display: 'flex',
              alignItems: 'center',
              gap: '6px'
            }">
              <span class="prefix-label" :style="{ fontSize: '0.75em', color: '#72777d', fontWeight: '600' }">Prefix:</span>
              <input
                class="prefix-input"
                type="text"
                :value="prefixInputValue"
                :placeholder="$t('labkipackmanager-pack-prefix-placeholder') || 'MyNamespace/MyPack'"
                :disabled="!isPackEditable"
                :readonly="!isPackEditable"
                @input="onPrefixChange"
                :style="{
                  fontSize: '0.72em',
                  padding: '4px 7px',
                  minWidth: '150px',
                  border: '1px solid #c8ccd1',
                  borderRadius: '3px',
                  background: 'white',
                  fontFamily: 'Monaco, Menlo, Consolas, monospace',
                  transition: 'all 0.15s ease',
                  cursor: isPackEditable ? 'text' : 'not-allowed'
                }"
              />
            </div>

            <!-- Actions (for packs only) - on third row -->
            <div class="actions" :style="{
              paddingLeft: `${depth * 24 + 32}px`,
              display: 'flex',
              gap: '8px',
              flexWrap: 'wrap'
            }">
              <div class="action-item" v-if="packState && packState.current_version === null">
                <cdx-button
                  action="progressive"
                  :weight="packState.action === 'install' ? 'primary' : 'normal'"
                  :class="{ active: packState.action === 'install' }"
                  @click="toggleInstall"
                >
                  {{ packState.action === 'install' ? '✓ ' : '' }}{{ $t('labkipackmanager-select') }}
                </cdx-button>
              </div>

              <div class="action-item" v-if="canUpdate">
                <cdx-button
                  :weight="packState?.action === 'update' ? 'primary' : 'normal'"
                  :class="{ active: packState?.action === 'update' }"
                  @click="toggleUpdate"
                >
                  {{ packState?.action === 'update' ? '✓ ' : '' }}{{ $t('labkipackmanager-update') }}
                </cdx-button>
              </div>

              <div class="action-item" v-if="packState && packState.current_version !== null">
                <cdx-button
                  action="destructive"
                  :weight="packState.action === 'remove' ? 'primary' : 'normal'"
                  :class="{ active: packState.action === 'remove' }"
                  @click="toggleRemove"
                >
                  {{ packState.action === 'remove' ? '✓ ' : '' }}{{ $t('labkipackmanager-remove') }}
                </cdx-button>
              </div>
            </div>
          </div>
          
          <!-- RIGHT COLUMN: Pages list (if any) -->
          <div v-if="childPages.length > 0" class="pages-column" :style="{
            flex: '1',
            minWidth: '250px',
            maxWidth: '400px',
            display: 'flex',
            flexDirection: 'column',
            gap: '4px',
            padding: '6px',
            background: 'rgba(248, 249, 250, 0.3)',
            borderRadius: '6px',
            border: '1px solid #eaecf0'
          }">
            <div :style="{ 
              fontSize: '0.7em', 
              color: '#72777d', 
              fontWeight: '700',
              textTransform: 'uppercase',
              letterSpacing: '0.5px',
              marginBottom: '2px',
              paddingLeft: '4px'
            }">
              📄 Pages ({{ childPages.length }})
            </div>
            
            <!-- Each page as a row with rename input -->
            <div 
              v-for="page in childPages"
              :key="page.id"
              class="page-row-inline"
              :style="{
                display: 'flex',
                alignItems: 'center',
                gap: '6px',
                padding: '4px 6px',
                background: getPageBackground(page.label),
                borderRadius: '4px',
                border: '1px solid #eaecf0',
                minHeight: '32px'
              }"
            >
              <span :style="{ 
                fontSize: '0.8em', 
                color: '#202122',
                fontWeight: '500',
                minWidth: '60px',
                maxWidth: '80px',
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap'
              }" :title="page.label">{{ page.label }}</span>
              
              <!-- Page rename editor (show if pack is selected for install/update or installed) -->
              <span v-if="showPackEditor" class="page-rename-inline" :style="{
                display: 'flex',
                alignItems: 'center',
                gap: '3px',
                flex: '1',
                minWidth: '0'
              }">
                <span class="arrow" :style="{ color: '#72777d', fontSize: '0.8em' }">→</span>
                <span v-if="getDisplayPrefix(page.label)" class="prefix-chip-inline" :style="{
                  fontSize: '0.7em',
                  padding: '3px 6px',
                  background: '#f0f0f0',
                  border: '1px solid #c8ccd1',
                  borderRight: 'none',
                  borderRadius: '3px 0 0 3px',
                  color: '#54595d',
                  whiteSpace: 'nowrap',
                  fontFamily: 'Monaco, Menlo, Consolas, monospace'
                }">{{ getDisplayPrefixWithSlash(page.label) }}</span>
                <input
                  class="page-input"
                  :class="{ 'has-collision': getPageHasCollision(page.label) }"
                  type="text"
                  :value="getPageEditableTitle(page.label)"
                  :placeholder="$t('labkipackmanager-page-title-placeholder') || 'PageTitle'"
                  :disabled="!getPageEditable(page.label)"
                  :readonly="!getPageEditable(page.label)"
                  @input="(e) => onPageTitleChangeForPage(e, page.label)"
                  @focus="() => console.log('[Page Input Focus] Page:', page.label, 'Editable:', getPageEditable(page.label), 'Pack action:', packState?.action)"
                  :aria-invalid="getPageHasCollision(page.label) ? 'true' : 'false'"
                  :style="{
                    fontSize: '0.72em',
                    padding: '4px 7px',
                    flex: '1',
                    minWidth: '100px',
                    border: getPageHasCollision(page.label) ? '1px solid #d33' : '1px solid #c8ccd1',
                    borderRadius: getDisplayPrefix(page.label) ? '0 3px 3px 0' : '3px',
                    background: getPageHasCollision(page.label) ? '#fff5f5' : 'white',
                    fontFamily: 'Monaco, Menlo, Consolas, monospace',
                    transition: 'all 0.15s ease',
                    cursor: getPageEditable(page.label) ? 'text' : 'not-allowed'
                  }"
                />
                <span v-if="getPageHasCollision(page.label)" class="collision-icon" :style="{ 
                  fontSize: '1.1em', 
                  cursor: 'help',
                  color: '#d33'
                }" :id="getCollisionId(page.label)" :title="getCollisionTooltip(page.label)">⚠️</span>
              </span>
            </div>
          </div>
        </div>
        
        <!-- Description and dependencies below both columns -->
        
        <!-- Description and dependencies on second row if present -->
        <div v-if="node.description || node.depends_on?.length" class="meta-row" :style="{
          marginTop: '6px',
          paddingLeft: '32px',
          paddingTop: '6px',
          borderTop: '1px solid #eaecf0'
        }">
          <div class="meta-content" :style="{
            display: 'flex',
            flexDirection: 'column',
            gap: '3px'
          }">
            <div v-if="node.description" class="desc" :style="{
              fontSize: '0.85em',
              color: '#54595d',
              lineHeight: '1.4'
            }">{{ node.description }}</div>
            <div v-if="node.depends_on?.length" class="depends" :style="{
              fontSize: '0.8em',
              color: '#72777d'
            }">
              <small>{{ $t('labkipackmanager-depends-on') }}: {{ node.depends_on.join(', ') }}</small>
            </div>
          </div>
        </div>

        <!-- 
          NESTED PACKS CONTAINER - Only renders nested PACKS (pages shown inline above)
          ⚠️ This is what creates the "recursive card nesting" effect
          - Children are inside the .pack-card div, not siblings
          - Each child pack creates its own card, nested inside this one
          - Visual indentation via left border + paddingLeft
        -->
        <transition
          name="children"
          @enter="onChildrenEnter"
          @leave="onChildrenLeave"
        >
          <div
            v-if="expanded && childPacks.length > 0"
            class="children"
            :style="{
              marginTop: '8px',             /* Space from parent content */
              paddingLeft: '16px',          /* Indent nested items */
              borderLeft: '2px solid #eaecf0',  /* Visual nesting indicator */
              paddingTop: '4px'             /* Top spacing */
            }"
          >
            <tree-node
              v-for="child in childPacks"
              :key="child.id"
              :node="child"
              :depth="depth + 1"
              :parent-pack-name="nodePackName"
              @set-pack-action="$emit('set-pack-action', $event)"
            />
          </div>
        </transition>
      </div>

      <!-- 
        PAGE ROW - Only rendered for standalone pages (not in a pack's children)
        This handles edge case where pages might be at root level
      -->
      <div 
        v-else-if="node.type === 'page'"
        class="page-row"
        :style="{
          padding: '8px 12px',
          background: pageState?.installed ? 'rgba(232, 240, 248, 0.4)' :          /* Blue if installed */
                      pageParentAction === 'install' ? 'rgba(232, 245, 233, 0.6)' : /* Green if installing */
                      pageParentAction === 'remove' ? 'rgba(255, 235, 238, 0.6)' :  /* Red if removing */
                      'rgba(255, 255, 255, 0.5)',                                    /* Transparent white */
          borderRadius: '6px',      /* Slightly rounded */
          marginBottom: '6px'       /* Space between pages */
        }"
      >
        <span class="toggle-spacer"></span>
        <span class="node-icon">📄</span>
        <strong class="label">{{ node.label }}</strong>
        
        <!-- Page rename editor -->
        <span v-if="showPageEditor" class="page-rename-inline">
          <span class="arrow">→</span>
          <span v-if="displayPrefix" class="prefix-chip-inline">{{ displayPrefixWithSlash }}</span>
          <input
            class="page-input"
            :class="{ 'has-collision': pageHasCollision }"
            type="text"
            :value="pageEditableTitle"
            :placeholder="$t('labkipackmanager-page-title-placeholder') || 'PageTitle'"
            :disabled="!isPageEditable"
            :readonly="!isPageEditable"
            @input="onPageTitleChange"
            :aria-invalid="pageHasCollision ? 'true' : 'false'"
            :aria-describedby="pageHasCollision ? collisionId : undefined"
          />
          <span v-if="pageHasCollision" class="collision-icon" :id="collisionId" :title="collisionTooltip">⚠️</span>
        </span>
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
  const action = packState.value?.action
  return action && action !== 'unchanged'
})
const isPackEditable = computed(() => {
  const action = packState.value?.action
  const installed = packState.value?.installed
  return action === 'install' && !installed
})
const showPackEditor = computed(() => {
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
  const action = parentPackState.value?.action
  return action === 'install' || action === 'update'
})
const isPageEditable = computed(() => {
  // For pages in the right column, check the pack's state (this node is the pack)
  if (props.node.type === 'pack') {
    const action = packState.value?.action
    return action === 'install' || action === 'update'
  }
  
  // For standalone page nodes (legacy), check parent pack
  const action = parentPackState.value?.action
  const installed = pageState.value?.installed
  return action === 'install' && !installed
})
const showPageEditor = computed(() => {
  const action = parentPackState.value?.action
  const installed = pageState.value?.installed
  return (action === 'install' || action === 'update') || installed
})
const pageParentAction = computed(() => parentPackState.value?.action || 'unchanged')
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

if (props.node.type === 'pack') {
  watch(() => packState.value?.action, (newAction, oldAction) => {
    console.log(`[TreeNode:${props.node.label}] packState.action changed: "${oldAction}" -> "${newAction}"`)
  })
}

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
  const current = packState.value?.action
  const next = current === action ? 'unchanged' : action
  console.log(`[TreeNode:${props.node.label}] toggleAction: current="${current}", requested="${action}", next="${next}"`)
  console.log(`[TreeNode:${props.node.label}] packState:`, packState.value)
  emit('set-pack-action', { pack_name: props.node.label, action: next })
}

function toggleInstall() {
  toggleAction('install')
}

function toggleRemove() {
  toggleAction('remove')
}

function toggleUpdate() {
  toggleAction('update')
}

function onPrefixChange(e) {
  if (!isPackEditable.value) return
  const val = e.target.value
  if (prefixTimer) clearTimeout(prefixTimer)
  prefixTimer = setTimeout(() => sendSetPackPrefixCommand(val), 400)
}

// Legacy function for standalone page nodes (edge case)
function onPageTitleChange(e) {
  if (!isPageEditable.value) return
  const editable = e.target.value
  if (pageTimer) clearTimeout(pageTimer)
  pageTimer = setTimeout(() => {
    const newTitle = displayPrefixWithSlash.value
      ? displayPrefixWithSlash.value + editable
      : editable
    sendRenamePageCommandLegacy(newTitle)
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

// Legacy function for standalone page nodes (edge case)
async function sendRenamePageCommandLegacy(newTitle) {
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

function getPageTitle(pageName) {
  const parentPack = store.packs[props.node.label]
  const pageState = parentPack?.pages?.[pageName]
  return pageState?.final_title || pageName
}

function getPageState(pageName) {
  const parentPack = store.packs[props.node.label]
  return parentPack?.pages?.[pageName] || null
}

function getPageBackground(pageName) {
  const pageState = getPageState(pageName)
  if (!pageState) return 'white'
  
  if (pageState.installed) return 'rgba(232, 240, 248, 0.4)'
  if (packState.value?.action === 'install') return 'rgba(232, 245, 233, 0.6)'
  if (packState.value?.action === 'remove') return 'rgba(255, 235, 238, 0.6)'
  return 'white'
}

function getDisplayPrefix(pageName) {
  return packState.value?.prefix || ''
}

function getDisplayPrefixWithSlash(pageName) {
  const prefix = getDisplayPrefix(pageName)
  return prefix ? (prefix.endsWith('/') ? prefix : prefix + '/') : ''
}

function getPageEditableTitle(pageName) {
  const pageState = getPageState(pageName)
  if (!pageState) return ''
  
  const full = pageState.final_title || ''
  const pref = getDisplayPrefixWithSlash(pageName)
  
  // Remove prefix from the title to show only the editable part
  if (pref && full.startsWith(pref)) {
    return full.slice(pref.length)
  }
  
  return full
}

function getPageHasCollision(pageName) {
  const pageState = getPageState(pageName)
  const full = pageState?.final_title
  if (!full || !props.node.label) return false
  return store.warnings.some(
    (w) => w.includes(full) && w.includes(props.node.label)
  )
}

function getCollisionTooltip(pageName) {
  const pageState = getPageState(pageName)
  const full = pageState?.final_title
  if (!full || !props.node.label) return ''
  return store.warnings
    .filter((w) => w.includes(full) && w.includes(props.node.label))
    .join('\n')
}

function getCollisionId(pageName) {
  return `collision-${props.node.label}-${pageName}`
}

function getPageEditable(pageName) {
  // Check if this specific page can be edited
  const pageState = getPageState(pageName)
  if (!pageState) return false
  
  const action = packState.value?.action
  
  // Can edit if pack is being installed (and page is not already installed)
  if (action === 'install' && !pageState.installed) return true
  
  // Can edit if pack is being updated
  if (action === 'update') return true
  
  return false
}

function onPageTitleChangeForPage(e, pageName) {
  // Check if pack is editable
  const action = packState.value?.action
  if (action !== 'install' && action !== 'update') {
    console.log('[onPageTitleChangeForPage] Pack not in install/update mode, ignoring input')
    return
  }
  
  const pageState = getPageState(pageName)
  if (!pageState) {
    console.log('[onPageTitleChangeForPage] No page state found for', pageName)
    return
  }
  
  // For install action, only allow editing if page is not already installed
  if (action === 'install' && pageState.installed) {
    console.log('[onPageTitleChangeForPage] Page already installed, cannot rename during install')
    return
  }
  
  const editable = e.target.value
  console.log('[onPageTitleChangeForPage] Page:', pageName, 'New value:', editable)
  
  if (pageTimer) clearTimeout(pageTimer)
  pageTimer = setTimeout(() => {
    const prefix = getDisplayPrefixWithSlash(pageName)
    const newTitle = prefix ? prefix + editable : editable
    console.log('[onPageTitleChangeForPage] Sending rename command. Pack:', props.node.label, 'Page:', pageName, 'Title:', newTitle)
    sendRenamePageCommand(pageName, newTitle)
  }, 400)
}

async function sendRenamePageCommand(pageName, newTitle) {
  if (store.busy) return
  try {
    store.busy = true
    const response = await packsAction({
      command: 'rename_page',
      repo_url: store.repoUrl,
      ref: store.ref,
      data: {
        pack_name: props.node.label,
        page_name: pageName,
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

// Separate pages and nested packs
const childPages = computed(() => {
  if (!props.node.children) return []
  return props.node.children.filter(child => child.type === 'page')
})

const childPacks = computed(() => {
  if (!props.node.children) return []
  return props.node.children.filter(child => child.type === 'pack')
})

// Smooth expand/collapse animations
function onChildrenEnter(el) {
  el.style.height = '0'
  el.style.opacity = '0'
  el.offsetHeight // Force reflow
  el.style.transition = 'height 0.3s ease, opacity 0.3s ease'
  el.style.height = el.scrollHeight + 'px'
  el.style.opacity = '1'
}

function onChildrenLeave(el) {
  el.style.height = el.scrollHeight + 'px'
  el.offsetHeight // Force reflow
  el.style.transition = 'height 0.3s ease, opacity 0.3s ease'
  el.style.height = '0'
  el.style.opacity = '0'
}
</script>

<style>
/*
  ============================================================================
  CSS CLASSES - SUPPLEMENTARY ONLY
  ============================================================================
  
  ⚠️ IMPORTANT: These CSS classes are NOT used for layout or visual styling!
  
  WHY: MediaWiki and Codex have extremely high CSS specificity that overrides
       our stylesheet classes. Inline styles (in the template above) have
       the highest specificity and cannot be overridden.
  
  WHAT THIS SECTION DOES:
  - Provides fallback styles for browsers without JS
  - Defines animation/transition effects
  - Sets display modes (flex, inline-flex, etc.) as base rules
  - Provides semantic class names for debugging
  
  WHAT THIS SECTION DOES NOT DO:
  - Control layout (padding, margin, borders) - use inline styles
  - Control colors (background, border-color) - use inline styles
  - Control visibility (shadows, opacity) - use inline styles
  
  IF YOU NEED TO CHANGE VISUAL APPEARANCE:
  1. Update the :style="" bindings in the <template> section above
  2. Do NOT add new CSS classes here expecting them to work
  3. Test by inspecting element in browser DevTools to verify inline styles
  
  ============================================================================
*/

/* ==================== TREE NODE BASE ==================== */

.labki-tree .tree-node {
  margin-bottom: 0 !important;
}

/* ==================== PACK CARD - PROPER NESTING ==================== */
/* Most styling is inline (see template) */

.labki-tree .pack-card {
  transition: all 0.2s ease !important;
}

/* Shadow hierarchy - deeper = lighter shadow */
.labki-tree .pack-card[data-depth="0"] {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1) !important;
}

.labki-tree .pack-card[data-depth="1"] {
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08) !important;
}

.labki-tree .pack-card[data-depth="2"] {
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06) !important;
}

.labki-tree .pack-card[data-depth="3"],
.labki-tree .pack-card[data-depth="4"],
.labki-tree .pack-card[data-depth="5"] {
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04) !important;
}

/* Subtle installed state */
.labki-tree .tree-node[data-installed="true"] .pack-card {
  background: #f8f9fa !important;
  border-color: #a2a9b1 !important;
}

/* Subtle action state overlays */
.labki-tree .tree-node[data-action="install"] .pack-card {
  background: linear-gradient(to right, #e8f5e9 0%, #ffffff 100%) !important;
  border-left: 3px solid #36c !important;
}

.labki-tree .tree-node[data-action="remove"] .pack-card {
  background: linear-gradient(to right, #ffebee 0%, #ffffff 100%) !important;
  border-left: 3px solid #d33 !important;
}

.labki-tree .tree-node[data-action="update"] .pack-card {
  background: linear-gradient(to right, #fff8e1 0%, #ffffff 100%) !important;
  border-left: 3px solid #fc3 !important;
}

.labki-tree .pack-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12) !important;
}

/* ==================== NODE ROW - HORIZONTAL LAYOUT ==================== */

.labki-tree .pack-card .node-row {
  display: flex !important;
  align-items: center !important;
  flex-wrap: nowrap !important;
  gap: 8px !important;
  min-height: 40px !important;
}

.labki-tree .name-section {
  display: inline-flex !important;
  align-items: center !important;
  gap: 8px !important;
  flex-shrink: 0 !important;
  flex-wrap: nowrap !important;
}

.labki-tree .spacer {
  display: inline-block !important;
  flex-grow: 1 !important;
  min-width: 12px !important;
}

/* ==================== TOGGLE & ICON ==================== */

.labki-tree .toggle {
  background: none !important;
  border: none !important;
  cursor: pointer !important;
  padding: 4px !important;
  font-size: 12px !important;
  color: #72777d !important;
  flex-shrink: 0 !important;
  width: 24px !important;
  height: 24px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  border-radius: 4px !important;
  transition: all 0.15s ease !important;
}

.labki-tree .toggle:hover {
  background: #eaecf0 !important;
  color: #202122 !important;
}

.labki-tree .toggle-spacer {
  width: 24px !important;
  flex-shrink: 0 !important;
  display: inline-block !important;
}

.labki-tree .node-icon {
  font-size: 20px !important;
  line-height: 1 !important;
  flex-shrink: 0 !important;
}

.labki-tree .label {
  font-size: 1em !important;
  font-weight: 600 !important;
  color: #202122 !important;
  white-space: nowrap !important;
}

/* ==================== BADGES ==================== */

.labki-tree .badge {
  display: inline-flex !important;
  align-items: center !important;
  font-size: 0.75em !important;
  padding: 4px 10px !important;
  border-radius: 12px !important;
  white-space: nowrap !important;
  font-weight: 500 !important;
  flex-shrink: 0 !important;
}

.labki-tree .badge.manual {
  background: #eaf3ff !important;
  color: #36c !important;
  border: 1px solid #b8d4ff !important;
}

.labki-tree .badge.auto {
  background: #fef6e7 !important;
  color: #ac6600 !important;
  border: 1px solid #fce29f !important;
}

.labki-tree .badge.version {
  background: #f0f0f0 !important;
  color: #54595d !important;
  border: 1px solid #c8ccd1 !important;
}

.labki-tree .badge.update {
  background: #fff3cd !important;
  color: #8a6d3b !important;
  border: 1px solid #fce29f !important;
  animation: pulse 2s infinite !important;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.7; }
}

/* ==================== INLINE EDITORS ==================== */

.labki-tree .prefix-editor-inline {
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  margin-left: 8px !important;
  flex-wrap: nowrap !important;
  flex-shrink: 0 !important;
}

.labki-tree .arrow {
  color: #72777d !important;
  font-size: 1.1em !important;
  flex-shrink: 0 !important;
}

.labki-tree .prefix-label {
  font-size: 0.85em !important;
  color: #54595d !important;
  white-space: nowrap !important;
  flex-shrink: 0 !important;
  font-weight: 500 !important;
}

.labki-tree .prefix-input {
  padding: 6px 10px !important;
  border: 1px solid #c8ccd1 !important;
  border-radius: 4px !important;
  font-size: 0.85em !important;
  font-family: 'Monaco', 'Menlo', 'Consolas', monospace !important;
  background: white !important;
  color: #202122 !important;
  width: 180px !important;
  flex-shrink: 0 !important;
  transition: all 0.15s ease !important;
}

.labki-tree .prefix-input:focus {
  outline: none !important;
  border-color: #36c !important;
  box-shadow: 0 0 0 1px #36c !important;
}

.labki-tree .prefix-input:disabled,
.labki-tree .prefix-input[readonly] {
  background: #f8f9fa !important;
  color: #72777d !important;
  cursor: not-allowed !important;
}

/* ==================== META ROW ==================== */
/* Most styling is inline (see template) */

/* ==================== CHILDREN CONTAINER (INSIDE CARD) ==================== */
/* Most styling is inline (see template) */

.labki-tree .children {
  overflow: hidden !important;
}

/* Smooth transitions */
.labki-tree .children-enter-active,
.labki-tree .children-leave-active {
  transition: all 0.3s ease !important;
}

.labki-tree .children-enter-from,
.labki-tree .children-leave-to {
  opacity: 0 !important;
  height: 0 !important;
}

/* ==================== PAGE ROW ==================== */

.labki-tree .page-row {
  display: flex !important;
  align-items: center !important;
  flex-wrap: nowrap !important;
  gap: 8px !important;
  padding: 8px 12px !important;
  background: rgba(255, 255, 255, 0.5) !important;
  border-radius: 6px !important;
  margin-bottom: 6px !important;
  transition: all 0.15s ease !important;
  min-height: 36px !important;
}

.labki-tree .page-row:hover {
  background: #f8f9fa !important;
}

.labki-tree .tree-node[data-installed="true"] .page-row {
  background: rgba(232, 240, 248, 0.4) !important;
}

.labki-tree .tree-node[data-action="install"] .page-row {
  background: rgba(232, 245, 233, 0.6) !important;
}

.labki-tree .tree-node[data-action="remove"] .page-row {
  background: rgba(255, 235, 238, 0.6) !important;
}

/* Page rename editor */
.labki-tree .page-rename-inline {
  display: inline-flex !important;
  align-items: center !important;
  gap: 0 !important;
  margin-left: 8px !important;
  flex-wrap: nowrap !important;
  flex-shrink: 0 !important;
}

.labki-tree .prefix-chip-inline {
  padding: 6px 10px !important;
  background: #f0f0f0 !important;
  border: 1px solid #c8ccd1 !important;
  border-right: none !important;
  border-radius: 4px 0 0 4px !important;
  font-size: 0.85em !important;
  font-family: 'Monaco', 'Menlo', 'Consolas', monospace !important;
  color: #54595d !important;
  white-space: nowrap !important;
}

.labki-tree .page-input {
  padding: 6px 10px !important;
  border: 1px solid #c8ccd1 !important;
  border-radius: 4px !important;
  font-size: 0.85em !important;
  font-family: 'Monaco', 'Menlo', 'Consolas', monospace !important;
  background: white !important;
  color: #202122 !important;
  width: 160px !important;
  flex-shrink: 0 !important;
  transition: all 0.15s ease !important;
}

.labki-tree .prefix-chip-inline + .page-input {
  border-radius: 0 4px 4px 0 !important;
}

.labki-tree .page-input:focus {
  outline: none !important;
  border-color: #36c !important;
  box-shadow: 0 0 0 1px #36c !important;
}

.labki-tree .page-input:disabled,
.labki-tree .page-input[readonly] {
  background: #f8f9fa !important;
  color: #72777d !important;
  cursor: not-allowed !important;
}

.labki-tree .page-input.has-collision {
  border-color: #d33 !important;
  background: #fff5f5 !important;
}

.labki-tree .collision-icon {
  font-size: 1.1em !important;
  cursor: help !important;
  margin-left: 4px !important;
}

/* ==================== ACTION BUTTONS ==================== */

.labki-tree .actions {
  display: inline-flex !important;
  align-items: center !important;
  gap: 8px !important;
  flex-shrink: 0 !important;
  flex-wrap: nowrap !important;
}

.labki-tree .action-item {
  display: inline-flex !important;
  flex-shrink: 0 !important;
}

.labki-tree :deep(.cdx-button) {
  display: inline-flex !important;
  font-size: 0.9em !important;
  padding: 8px 16px !important;
  transition: all 0.2s ease !important;
  white-space: nowrap !important;
  flex-shrink: 0 !important;
  border-radius: 6px !important;
}

/* Active state styling */
.labki-tree :deep(.cdx-button.active[action="progressive"]) {
  background-color: #2a4b8d !important;
  border-color: #2a4b8d !important;
  color: white !important;
  font-weight: 600 !important;
  box-shadow: 0 0 0 3px rgba(54, 108, 204, 0.2) !important;
  transform: scale(1.02) !important;
}

.labki-tree :deep(.cdx-button.active[action="destructive"]) {
  background-color: #d73333 !important;
  border-color: #d73333 !important;
  color: white !important;
  font-weight: 600 !important;
  box-shadow: 0 0 0 3px rgba(215, 51, 51, 0.2) !important;
  transform: scale(1.02) !important;
}

.labki-tree :deep(.cdx-button.active:not([action="progressive"]):not([action="destructive"])) {
  background-color: #fc3 !important;
  border-color: #fc3 !important;
  color: #202122 !important;
  font-weight: 600 !important;
  box-shadow: 0 0 0 3px rgba(255, 204, 51, 0.2) !important;
  transform: scale(1.02) !important;
}

.labki-tree :deep(.cdx-button:hover) {
  transform: translateY(-1px) !important;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
}

.labki-tree :deep(.cdx-button:active) {
  transform: translateY(0) !important;
}

/* ==================== RESPONSIVE ADJUSTMENTS ==================== */

@media (max-width: 768px) {
  .labki-tree .pack-card {
    padding: 10px !important;
  }
  
  .labki-tree .node-row {
    flex-wrap: wrap !important;
  }
  
  .labki-tree .actions {
    width: 100% !important;
    justify-content: flex-start !important;
    margin-top: 8px !important;
  }
  
  .labki-tree .prefix-editor-inline,
  .labki-tree .page-rename-inline {
    width: 100% !important;
    margin-left: 0 !important;
    margin-top: 8px !important;
  }
}
</style>