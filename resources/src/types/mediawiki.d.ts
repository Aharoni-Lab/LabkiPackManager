/**
 * Basic MediaWiki type declarations.
 *
 * Minimal type definitions for MediaWiki global objects.
 */

import type {
  ActionAPIRequest,
  ActionAPIResponseMap,
  ActionAPIRequest,
  ActionAPIRequest,
} from '../state/types';

declare global {
  const mw: {
    msg: (key: string, ...params: string[]) => string;
    loader: {
      using: (modules: string | string[]) => Promise<void>;
      getState: (module: string) => string | null;
    };
    Api: new () => {
      get: <T extends keyof ActionAPIResponseMap>(
        params: ActionAPIRequest<T>,
      ) => Promise<ActionAPIResponseMap[T]>;
      post: <T extends keyof ActionAPIResponseMap>(
        params: ActionAPIRequest<T>,
      ) => Promise<ActionAPIResponseMap[T]>;
    };
  };
}

export {};
