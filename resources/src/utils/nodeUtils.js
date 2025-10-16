/**
 * Node utilities shared across components.
 */

/**
 * Derive the canonical pack name from an id like "pack:foo" or from a node.
 * Prefers an explicit node.name if available.
 */
export function idToName(id, node) {
  if (node && typeof node.name === 'string' && node.name) return node.name;
  const i = String(id).indexOf(':');
  return i > 0 ? String(id).slice(i + 1) : String(id);
}

/**
 * Determine whether an id/node represents a pack node.
 * Uses node.type when available, otherwise checks the id prefix.
 */
export function isPackNode(id, node) {
  if (node && typeof node.type === 'string') return node.type === 'pack';
  return String(id).startsWith('pack:');
}


