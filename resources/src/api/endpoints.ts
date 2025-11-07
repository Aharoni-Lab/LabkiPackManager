/**
 * Typed API endpoint functions for Labki Pack Manager.
 */

import { getApi, apiCall } from './mwApi';
import type {
  ReposListResponse,
  ReposAddResponse,
  GraphGetResponse,
  HierarchyGetResponse,
  PacksActionPayload,
  PacksActionResponse,
} from '../state/types';

/**
 * List all content repositories.
 */
export async function reposList(): Promise<ReposListResponse> {
  return apiCall(async () => {
    console.log('[reposList] Starting API call...');
    const api = getApi();
    console.log('[reposList] API instance:', api);

    const response = await api.get({
      action: 'labkiReposList',
      format: 'json',
    });
    console.log('[reposList] Raw API response:', response);
    console.log('[reposList] Response keys:', Object.keys(response));

    if (!response) {
      throw new Error('No response from API');
    }

    // Try different ways to get the data
    let data = response.labkiReposList;
    if (!data) {
      // Maybe the response is already unwrapped?
      if (response.repos && response.meta) {
        console.log('[reposList] Response appears to be unwrapped, using directly');
        data = response;
      } else {
        console.error(
          '[reposList] Cannot find repos data in response:',
          JSON.stringify(response, null, 2),
        );
        throw new Error(
          'Invalid API response structure - no labkiReposList field and no repos field',
        );
      }
    }

    console.log('[reposList] Data structure:', data);
    console.log('[reposList] Returning:', data);
    return data as ReposListResponse;
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
    console.log('[reposAdd] Sending request:', { repo_url: repoUrl, default_ref: defaultRef });

    const response = await api.post({
      action: 'labkiReposAdd',
      format: 'json',
      repo_url: repoUrl,
      default_ref: defaultRef,
    });

    console.log('[reposAdd] Raw API response:', response);
    console.log('[reposAdd] Response keys:', Object.keys(response));

    // MediaWiki might wrap or not wrap depending on context
    const data = response.labkiReposAdd || response;
    console.log('[reposAdd] Returning data:', data);

    return data as ReposAddResponse;
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
    const response = await api.get({
      action: 'labkiGraphGet',
      format: 'json',
      repo_url: repoUrl,
      ref: ref,
    });
    console.log('[graphGet] Response:', response);

    // Try wrapped response first
    let data = response.labkiGraphGet;
    if (!data) {
      // Try unwrapped response
      if (response.graph && response.repo_url && response.ref) {
        console.log('[graphGet] Using unwrapped response');
        data = response;
      } else {
        throw new Error('Invalid graphGet response structure');
      }
    }
    return data as GraphGetResponse;
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
    const response = await api.get({
      action: 'labkiHierarchyGet',
      format: 'json',
      repo_url: repoUrl,
      ref: ref,
    });
    console.log('[hierarchyGet] Response:', response);

    // Try wrapped response first
    let data = response.labkiHierarchyGet;
    if (!data) {
      // Try unwrapped response
      if (response.hierarchy && response.repo_url && response.ref) {
        console.log('[hierarchyGet] Using unwrapped response');
        data = response;
      } else {
        throw new Error('Invalid hierarchyGet response structure');
      }
    }
    return data as HierarchyGetResponse;
  });
}

/**
 * Execute a pack action command.
 *
 * @param payload - Action payload
 */
export async function packsAction(payload: PacksActionPayload): Promise<PacksActionResponse> {
  return apiCall(async () => {
    const api = getApi();
    console.log('[packsAction] Sending payload:', payload);

    const response = await api.post({
      action: 'labkiPacksAction',
      format: 'json',
      payload: JSON.stringify(payload),
    });

    console.log('[packsAction] Raw response:', response);

    // Response is always wrapped in labkiPacksAction
    const data = response.labkiPacksAction;
    if (!data) {
      console.error(
        '[packsAction] Cannot find labkiPacksAction in response:',
        JSON.stringify(response, null, 2),
      );
      throw new Error('Invalid packsAction response structure');
    }

    console.log('[packsAction] Returning:', data);
    return data as PacksActionResponse;
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
    const response = await api.get({
      action: 'labkiOperationsStatus',
      format: 'json',
      operation_id: operationId,
    });

    console.log('[operationStatus] Raw response:', response);
    console.log('[operationStatus] Response keys:', Object.keys(response));
    console.log('[operationStatus] status field:', response?.status);
    console.log('[operationStatus] operation_id field:', response?.operation_id);
    return response;
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
  onProgress?: (status: any) => void,
): Promise<any> {
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
      console.log('[pollOperation] Operation completed successfully:', status);
      return status;
    }

    if (status.status === 'failed') {
      console.error('[pollOperation] Operation failed:', status.message);
      throw new Error(`Operation failed: ${status.message}`);
    }

    // Status is 'queued' or 'running' - continue polling
    if (attempt < maxAttempts - 1) {
      await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }
  }

  throw new Error(`Operation timed out after ${maxAttempts} attempts`);
}
