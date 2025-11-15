/**
 * Test setup file for Vitest
 *
 * This file is automatically loaded before all tests.
 */

import { vi } from 'vitest';

// Mock MediaWiki's mw object
global.mw = {
  config: {
    get: vi.fn((key: string) => {
      const configMap: Record<string, any> = {
        wgScriptPath: '/w',
        wgServer: 'http://localhost',
      };
      return configMap[key];
    }),
  },
  Api: vi.fn(() => ({
    get: vi.fn(() => Promise.resolve({})),
    post: vi.fn(() => Promise.resolve({})),
  })),
  message: vi.fn((key: string) => ({
    text: () => key,
    parse: () => key,
  })),
  msg: vi.fn((key: string) => key),
} as any;

// Mock i18n for Vue components
global.$t = (key: string) => key;
