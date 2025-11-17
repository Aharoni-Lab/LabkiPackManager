/**
 * MediaWiki API wrapper with error handling.
 */

/**
 * Get a singleton instance of the MediaWiki API.
 */
export function getApi() {
  return new mw.Api();
}

/**
 * Wrap an API call with error handling.
 *
 * @param fn - API call function
 * @returns Promise with typed result
 */
export async function apiCall<T>(fn: () => Promise<T>): Promise<T> {
  try {
    const res = await fn();
    console.log('API Response', fn, res);
    return res;
  } catch (error) {
    console.error('API call failed:', error);
    throw error;
  }
}
