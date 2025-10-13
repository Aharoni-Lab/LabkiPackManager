// Minimal globals for tests
// eslint-disable-next-line @typescript-eslint/no-explicit-any
(global as any).mw = {
  util: { wikiScript: () => '/w/api.php' },
  config: { get: () => [] }
};


