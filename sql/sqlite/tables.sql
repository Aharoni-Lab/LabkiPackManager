
-- labki_content_repo
--  └── labki_content_ref
--          └── labki_pack
--                  └── labki_page

-- ===========================================================
--  labki_content_repo: top-level content repository (bare clone)
-- ===========================================================
CREATE TABLE labki_content_repo (
  content_repo_id     INTEGER PRIMARY KEY AUTOINCREMENT,
  content_repo_url    TEXT NOT NULL UNIQUE,   -- canonical repository URL (e.g., https://github.com/Aharoni-Lab/labki-packs)
  content_repo_name   TEXT,                   -- manifest-defined or inferred name
  default_ref         TEXT DEFAULT 'main',    -- repo’s default branch
  bare_path           TEXT,                   -- filesystem path to the bare clone
  last_fetched        INTEGER,                -- timestamp of last git fetch
  created_at          INTEGER NOT NULL,
  updated_at          INTEGER NOT NULL
);

-- ===========================================================
--  labki_content_ref: per-branch/tag/commit (worktree level)
-- ===========================================================
CREATE TABLE labki_content_ref (
  content_ref_id          INTEGER PRIMARY KEY AUTOINCREMENT,
  content_repo_id         INTEGER NOT NULL,
  source_ref              TEXT NOT NULL,         -- branch, tag, or commit name
  last_commit             TEXT,                  -- HEAD commit hash from last successful checkout
  manifest_path           TEXT,                  -- path to manifest within repo
  manifest_hash           TEXT,                  -- hash of manifest contents
  manifest_last_parsed    INTEGER,               -- timestamp of last manifest parse
  worktree_path           TEXT,                  -- filesystem path to this worktree
  created_at              INTEGER NOT NULL,
  updated_at              INTEGER NOT NULL,
  UNIQUE (content_repo_id, source_ref),
  FOREIGN KEY (content_repo_id)
    REFERENCES labki_content_repo (content_repo_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
);

-- ===========================================================
--  labki_pack: per-pack info within a specific content repo ref
-- ===========================================================
CREATE TABLE IF NOT EXISTS labki_pack (
  pack_id INTEGER PRIMARY KEY AUTOINCREMENT,
  content_ref_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  version TEXT,
  source_commit TEXT,
  installed_at INTEGER,
  installed_by INTEGER,
  updated_at INTEGER,
  status TEXT CHECK(status IN ('installed','pending','removed','error')) DEFAULT 'installed',
  UNIQUE (content_ref_id, name),
  FOREIGN KEY (content_ref_id) REFERENCES labki_content_ref (content_ref_id)
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

-- Core relational indexes
CREATE INDEX idx_labki_ref_repo            ON labki_content_ref (content_repo_id);
CREATE INDEX idx_labki_pack_ref            ON labki_pack (content_ref_id);
CREATE INDEX idx_labki_page_pack           ON labki_page (pack_id);
CREATE INDEX idx_labki_page_final_title    ON labki_page (final_title);
CREATE INDEX idx_labki_page_wiki_page_id   ON labki_page (wiki_page_id);

-- Performance and metadata indexes
CREATE INDEX idx_labki_repo_url            ON labki_content_repo (content_repo_url);
CREATE INDEX idx_labki_repo_name           ON labki_content_repo (content_repo_name);
CREATE INDEX idx_labki_repo_last_fetched   ON labki_content_repo (last_fetched);

CREATE INDEX idx_labki_ref_source_ref      ON labki_content_ref (source_ref);
CREATE INDEX idx_labki_ref_last_commit     ON labki_content_ref (last_commit);
CREATE INDEX idx_labki_ref_manifest_hash   ON labki_content_ref (manifest_hash);
