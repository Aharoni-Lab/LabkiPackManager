/**
 * Version utilities for semantic-like x.y.z comparisons.
 */

/**
 * Get major version number (integer) from a version string like "1.2.3".
 * Returns 0 on malformed inputs.
 * @param {string} v
 * @returns {number}
 */
export function major(v) {
  if (!v || typeof v !== 'string') return 0;
  const m = parseInt(v.split('.')[0] || '0', 10);
  return Number.isFinite(m) ? m : 0;
}

/**
 * Compare versions a and b (x.y.z strings).
 * Returns 1 if a>b, -1 if a<b, 0 if equal.
 * Missing parts are treated as 0.
 * @param {string} a
 * @param {string} b
 * @returns {number}
 */
export function compareVersions(a, b) {
  const pa = String(a || '').split('.').map(n => parseInt(n, 10)).map(n => (Number.isFinite(n) ? n : 0));
  const pb = String(b || '').split('.').map(n => parseInt(n, 10)).map(n => (Number.isFinite(n) ? n : 0));
  for (let i = 0; i < 3; i++) {
    const av = pa[i] ?? 0;
    const bv = pb[i] ?? 0;
    if (av > bv) return 1;
    if (av < bv) return -1;
  }
  return 0;
}


