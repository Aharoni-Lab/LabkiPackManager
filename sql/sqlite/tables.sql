-- labki_content_repo: top-level content repository
CREATE TABLE IF NOT EXISTS labki_content_repo (
  repo_id INTEGER PRIMARY KEY AUTOINCREMENT,
  repo_url TEXT NOT NULL,
  default_ref TEXT,
  created_at INTEGER,
  updated_at INTEGER,
  UNIQUE (repo_url)
);

-- labki_pack: per-pack info within a content repo
CREATE TABLE IF NOT EXISTS labki_pack (
  pack_id INTEGER PRIMARY KEY AUTOINCREMENT,
  repo_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  version TEXT,
  source_ref TEXT,
  source_commit TEXT,
  installed_at INTEGER,
  installed_by INTEGER,
  UNIQUE (repo_id, name),
  FOREIGN KEY (repo_id) REFERENCES labki_content_repo (repo_id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- labki_page: per-page info within a pack (includes name mapping)
CREATE TABLE IF NOT EXISTS labki_page (
  page_id INTEGER PRIMARY KEY AUTOINCREMENT,
  pack_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  final_title TEXT NOT NULL,
  page_namespace INTEGER NOT NULL,
  wiki_page_id INTEGER,
  last_rev_id INTEGER,
  content_hash TEXT,
  created_at INTEGER,
  UNIQUE (pack_id, name),
  UNIQUE (pack_id, final_title),
  FOREIGN KEY (pack_id) REFERENCES labki_pack (pack_id) ON DELETE CASCADE ON UPDATE CASCADE
);
