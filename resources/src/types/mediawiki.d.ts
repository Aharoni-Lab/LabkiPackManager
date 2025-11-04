/**
 * Basic MediaWiki type declarations.
 * 
 * Minimal type definitions for MediaWiki global objects.
 */

declare global {
  const mw: {
    msg: (key: string, ...params: string[]) => string;
    loader: {
      using: (modules: string | string[]) => Promise<void>;
      getState: (module: string) => string | null;
      require: (module: string) => any;
    };
    Api: new () => {
      get: (params: Record<string, any>) => Promise<any>;
      post: (params: Record<string, any>) => Promise<any>;
    };
  };
}

export {};

