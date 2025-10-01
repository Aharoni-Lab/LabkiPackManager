-- labki_pack_registry: per installed pack
CREATE TABLE IF NOT EXISTS labki_pack_registry (
  pack_id TEXT PRIMARY KEY,
  version TEXT,
  source_repo TEXT,
  source_ref TEXT,
  source_commit TEXT,
  installed_at INTEGER,
  installed_by INTEGER
);

-- labki_pack_pages: pages belonging to an installed pack
CREATE TABLE IF NOT EXISTS labki_pack_pages (
  pack_id TEXT NOT NULL,
  page_title TEXT NOT NULL,
  page_namespace INTEGER NOT NULL,
  page_id INTEGER,
  last_rev_id INTEGER,
  content_hash TEXT,
  PRIMARY KEY (pack_id, page_title)
);


