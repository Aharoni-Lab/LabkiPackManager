-- labki_pack_registry: per installed pack
CREATE TABLE IF NOT EXISTS labki_pack_registry (
  pack_uid TEXT PRIMARY KEY,
  pack_id TEXT,
  version TEXT,
  source_repo TEXT,
  source_ref TEXT,
  source_commit TEXT,
  installed_at INTEGER,
  installed_by INTEGER
);

-- labki_pack_pages: pages belonging to an installed pack
CREATE TABLE IF NOT EXISTS labki_pack_pages (
  pack_uid TEXT NOT NULL,
  pack_id TEXT,
  page_title TEXT NOT NULL,
  page_namespace INTEGER NOT NULL,
  page_id INTEGER,
  last_rev_id INTEGER,
  content_hash TEXT,
  PRIMARY KEY (pack_uid, page_title)
);

-- labki_page_mapping: remembers original page keys to final titles
CREATE TABLE IF NOT EXISTS labki_page_mapping (
  pack_uid TEXT NOT NULL,
  pack_id TEXT,
  page_key TEXT NOT NULL,
  final_title TEXT NOT NULL,
  created_at INTEGER,
  PRIMARY KEY (pack_uid, page_key)
);


