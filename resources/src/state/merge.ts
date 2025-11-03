/**
 * State diff merge logic.
 * 
 * Merges API response diffs into local state, preserving unchanged fields.
 */

import type { PacksState, PackState, PackPageState } from './types';

/**
 * Merge a diff into the target packs state.
 * 
 * @param target - Target state to merge into (mutated in place)
 * @param diff - Diff from API response
 */
export function mergeDiff(target: PacksState, diff: PacksState): void {
  for (const [packName, packChanges] of Object.entries(diff)) {
    const existing = target[packName] ?? {};
    target[packName] = mergePack(existing, packChanges);
  }
}

/**
 * Merge pack-level changes.
 * 
 * @param dst - Destination pack state
 * @param src - Source pack changes
 * @returns Merged pack state
 */
function mergePack(dst: PackState, src: PackState): PackState {
  // Shallow merge pack-level fields
  // Server is authoritative for all fields including action
  const out: PackState = { ...dst, ...src };

  // Deep merge pages if present in source
  if (src.pages) {
    out.pages = { ...(dst.pages ?? {}) };
    for (const [pageName, pageChanges] of Object.entries(src.pages)) {
      const existingPage = out.pages[pageName] ?? {};
      out.pages[pageName] = mergePage(existingPage, pageChanges);
    }
  }

  return out;
}

/**
 * Merge page-level changes.
 * 
 * @param dst - Destination page state
 * @param src - Source page changes
 * @returns Merged page state
 */
function mergePage(dst: PackPageState, src: PackPageState): PackPageState {
  return { ...dst, ...src };
}

