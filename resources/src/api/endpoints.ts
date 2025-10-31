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
        console.error('[reposList] Cannot find repos data in response:', JSON.stringify(response, null, 2));
        throw new Error('Invalid API response structure - no labkiReposList field and no repos field');
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
export async function reposAdd(
  repoUrl: string,
  defaultRef: string
): Promise<ReposAddResponse> {
  return apiCall(async () => {
    const api = getApi();
    const response = await api.post({
      action: 'labkiReposAdd',
      format: 'json',
      repo_url: repoUrl,
      default_ref: defaultRef,
    });
    return response.labkiReposAdd as ReposAddResponse;
  });
}

/**
 * Get dependency graph for a repository/ref.
 * 
 * @param repoUrl - Repository URL
 * @param ref - Reference (branch/tag)
 */
export async function graphGet(
  repoUrl: string,
  ref: string
): Promise<GraphGetResponse> {
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
export async function hierarchyGet(
  repoUrl: string,
  ref: string
): Promise<HierarchyGetResponse> {
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
export async function packsAction(
  payload: PacksActionPayload
): Promise<PacksActionResponse> {
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
    let data = response.labkiPacksAction;
    if (!data) {
      console.error('[packsAction] Cannot find labkiPacksAction in response:', JSON.stringify(response, null, 2));
      throw new Error('Invalid packsAction response structure');
    }
    
    console.log('[packsAction] Returning:', data);
    return data as PacksActionResponse;
  });
}

