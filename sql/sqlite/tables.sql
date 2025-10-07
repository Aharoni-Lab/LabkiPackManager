-- ===========================================================
--  labki_content_repo: top-level content repository
-- ===========================================================
CREATE TABLE IF NOT EXISTS labki_content_repo (
  content_repo_id INTEGER PRIMARY KEY AUTOINCREMENT,
  content_repo_url TEXT NOT NULL,
  content_repo_name TEXT,
  default_ref TEXT,
  created_at INTEGER,
  updated_at INTEGER,
  UNIQUE (content_repo_url)
);

-- ===========================================================
--  labki_pack: per-pack info within a content repo
-- ===========================================================
CREATE TABLE IF NOT EXISTS labki_pack (
  pack_id INTEGER PRIMARY KEY AUTOINCREMENT,
  content_repo_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  version TEXT,
  source_ref TEXT,
  source_commit TEXT,
  installed_at INTEGER,
  installed_by INTEGER,
  updated_at INTEGER,
  status TEXT CHECK(status IN ('installed','pending','removed','error')) DEFAULT 'installed',
  UNIQUE (content_repo_id, name, version),
  FOREIGN KEY (content_repo_id) REFERENCES labki_content_repo (content_repo_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  FOREIGN KEY (installed_by) REFERENCES user (user_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE
);

-- ===========================================================
--  labki_page: per-page info within a pack (includes name mapping)
-- ===========================================================
CREATE TABLE IF NOT EXISTS labki_page (
  page_id INTEGER PRIMARY KEY AUTOINCREMENT,
  pack_id INTEGER NOT NULL,
  name TEXT NOT NULL,                 -- original name in the pack
  final_title TEXT NOT NULL,          -- actual wiki page title (after rename/prefix)
  page_namespace INTEGER NOT NULL,    -- MediaWiki namespace ID
  wiki_page_id INTEGER,               -- link to core 'page' table
  last_rev_id INTEGER,                -- link to latest revision installed
  content_hash TEXT,                  -- hash of installed content for drift detection
  created_at INTEGER,
  updated_at INTEGER,
  UNIQUE (pack_id, name),
  UNIQUE (pack_id, final_title),
  FOREIGN KEY (pack_id) REFERENCES labki_pack (pack_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
);

-- ===========================================================
--  Helpful indexes
-- ===========================================================
CREATE INDEX IF NOT EXISTS idx_labki_pack_repo
  ON labki_pack (content_repo_id);

CREATE INDEX IF NOT EXISTS idx_labki_page_pack
  ON labki_page (pack_id);

CREATE INDEX IF NOT EXISTS idx_labki_page_final_title
  ON labki_page (final_title);

CREATE INDEX IF NOT EXISTS idx_labki_page_wiki_page_id
  ON labki_page (wiki_page_id);
