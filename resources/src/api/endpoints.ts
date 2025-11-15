/**
 * Typed API endpoint functions for Labki Pack Manager.
 */

import { getApi, apiCall } from './mwApi';
import type {
  ReposListResponse,
  ReposAddResponse,
  GraphGetResponse,
  HierarchyGetResponse,
  PacksActionCommandBase,
  PacksActionResponse,
  OperationsStatusResponse,
  ReposSyncRequest,
  ReposSyncResponse
} from '../state/types';

/**
 * List all content repositories.
 */
export async function reposList(): Promise<ReposListResponse> {
  return apiCall(async () => {
    const api = getApi();
    const response = await api.get({
      action: 'labkiReposList',
      format: 'json',
    });
    if (!response) {
      throw new Error('No response from API');
    }
    return response;
  });
}

/**
 * Add a new content repository.
 *
 * @param repoUrl - Repository URL
 * @param defaultRef - Default ref (branch/tag)
 */
export async function reposAdd(repoUrl: string, defaultRef: string): Promise<ReposAddResponse> {
  return apiCall(async () => {
    const api = getApi();

    return await api.post({
      action: 'labkiReposAdd',
      format: 'json',
      repo_url: repoUrl,
      default_ref: defaultRef,
    });
  });
}

/**
 * Sync a repository from remote.
 *
 * @param repoUrl - Repository URL
 * @param refs - Optional array of specific refs to sync (if not provided, syncs entire repo)
 */
export async function reposSync(
  repoUrl: string,
  refs?: string[]
): Promise<ReposSyncResponse> {
  return apiCall(async () => {
    const api = getApi();
    console.log('[reposSync] Sending request:', { repo_url: repoUrl, refs });

    const params: ReposSyncRequest = {
      action: 'labkiReposSync',
      format: 'json',
      repo_url: repoUrl,
    };

    if (refs && refs.length > 0) {
      params.refs = refs.join('|');
    }

    const response = await api.post(params);

    console.log('[reposSync] Raw API response:', response);

    // MediaWiki might wrap or not wrap depending on context
    return response;
  });
}

/**
 * Get dependency graph for a repository/ref.
 *
 * @param repoUrl - Repository URL
 * @param ref - Reference (branch/tag)
 */
export async function graphGet(repoUrl: string, ref: string): Promise<GraphGetResponse> {
  return apiCall(async () => {
    const api = getApi();
    return await api.get({
      action: 'labkiGraphGet',
      format: 'json',
      repo_url: repoUrl,
      ref: ref,
    });
  });
}

/**
 * Get hierarchy tree for a repository/ref.
 *
 * @param repoUrl - Repository URL
 * @param ref - Reference (branch/tag)
 */
export async function hierarchyGet(repoUrl: string, ref: string): Promise<HierarchyGetResponse> {
  return apiCall(async () => {
    const api = getApi();
    return await api.get({
      action: 'labkiHierarchyGet',
      format: 'json',
      repo_url: repoUrl,
      ref: ref,
    });
  });
}

/**
 * Execute a pack action command.
 *
 * @param payload - Action payload
 */
export async function packsAction(payload: PacksActionCommandBase): Promise<PacksActionResponse> {
  return apiCall(async () => {
    const api = getApi();

    const response = await api.post({
      action: 'labkiPacksAction',
      format: 'json',
      payload: JSON.stringify(payload),
    });
    return response.labkiPacksAction;
  });
}

/**
 * Get status of a background operation.
 *
 * @param operationId - Operation ID to check
 */
export async function operationStatus(operationId: string) {
  return apiCall(async () => {
    const api = getApi();
    return await api.get({
      action: 'labkiOperationsStatus',
      format: 'json',
      operation_id: operationId,
    });
  });
}

/**
 * Poll an operation until it completes (success or failure).
 *
 * @param operationId - Operation ID to poll
 * @param maxAttempts - Maximum number of polling attempts (default: 60)
 * @param intervalMs - Polling interval in milliseconds (default: 1000)
 * @param onProgress - Optional callback called on each poll with status
 * @returns Promise that resolves when operation completes
 * @throws Error if operation fails or times out
 */
export async function pollOperation(
  operationId: string,
  maxAttempts = 60,
  intervalMs = 1000,
  onProgress?: (status: OperationsStatusResponse) => void,
): Promise<OperationsStatusResponse> {
  console.log(`[pollOperation] Starting poll for ${operationId}`);

  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    const status = await operationStatus(operationId);

    console.log(
      `[pollOperation] Attempt ${attempt + 1}/${maxAttempts}, status: ${status.status}, progress: ${status.progress}%, message: ${status.message}`,
    );

    // Call progress callback if provided
    if (onProgress) {
      onProgress(status);
    }

    if (status.status === 'success') {
      console.log('[pollOperation] ✓ SUCCESS detected! Operation completed successfully:', status);
      return status;
    }

    if (status.status === 'failed') {
      console.error('[pollOperation] ✗ FAILED detected! Operation failed:', status.message);
      throw new Error(`Operation failed: ${status.message}`);
    }

    // Status is 'queued' or 'running' - continue polling
    if (attempt < maxAttempts - 1) {
      await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }
  }

  console.error('[pollOperation] ⏱ TIMEOUT after', maxAttempts, 'attempts');
  throw new Error(`Operation timed out after ${maxAttempts} attempts`);
}
