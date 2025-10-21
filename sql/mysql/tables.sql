-- ===========================================================
--  labki_content_repo: top-level content repository
-- ===========================================================
CREATE TABLE IF NOT EXISTS /*_*/labki_content_repo (
  content_repo_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_repo_url         VARBINARY(512) NOT NULL,    -- canonical repository URL
  content_repo_name        VARBINARY(255) DEFAULT NULL, -- manifest-defined or inferred name
  source_ref               VARBINARY(255) DEFAULT NULL, -- branch, tag, or commit actually used
  manifest_path            VARBINARY(255) DEFAULT NULL, -- relative path to manifest file
  manifest_hash            VARBINARY(64)  DEFAULT NULL, -- hash of manifest contents
  manifest_last_parsed     BINARY(14)     DEFAULT NULL, -- MW timestamp of last parse
  last_commit              VARBINARY(64)  DEFAULT NULL, -- HEAD commit hash from last pull
  created_at               BINARY(14)     NOT NULL,     -- MW timestamp (wfTimestampNow)
  updated_at               BINARY(14)     NOT NULL,     -- MW timestamp (wfTimestampNow)
  PRIMARY KEY (content_repo_id),
  UNIQUE KEY uq_repo_url_ref (content_repo_url, source_ref)
) /*$wgDBTableOptions*/;


-- ===========================================================
--  labki_pack: per-pack info within a content repo
-- ===========================================================
CREATE TABLE IF NOT EXISTS /*_*/labki_pack (
  pack_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_repo_id  INT UNSIGNED NOT NULL,
  name             VARBINARY(255) NOT NULL,
  version          VARBINARY(64)  DEFAULT NULL,
  source_ref       VARBINARY(255) DEFAULT NULL, -- ref of repo used to fetch this pack
  source_commit    VARBINARY(64)  DEFAULT NULL, -- commit of repo used to fetch this pack
  installed_at     BINARY(14)     DEFAULT NULL,
  installed_by     INT UNSIGNED   DEFAULT NULL,
  updated_at       BINARY(14)     DEFAULT NULL,
  status           VARBINARY(16)  DEFAULT 'installed',
  PRIMARY KEY (pack_id),
  UNIQUE KEY uq_content_repo_name (content_repo_id, name),
  CONSTRAINT fk_pack_content_repo_id FOREIGN KEY (content_repo_id)
    REFERENCES /*_*/labki_content_repo (content_repo_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_pack_installed_by FOREIGN KEY (installed_by)
    REFERENCES /*_*/user (user_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) /*$wgDBTableOptions*/;


-- ===========================================================
--  labki_page: per-page info within a pack (includes name mapping)
-- ===========================================================
CREATE TABLE IF NOT EXISTS /*_*/labki_page (
  page_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pack_id         INT UNSIGNED NOT NULL,
  name            VARBINARY(512) NOT NULL, -- original name in pack
  final_title     VARBINARY(512) NOT NULL, -- actual wiki title (after rename/prefix)
  page_namespace  INT NOT NULL,            -- MediaWiki namespace ID
  wiki_page_id    INT UNSIGNED DEFAULT NULL,
  last_rev_id     INT UNSIGNED DEFAULT NULL,
  content_hash    VARBINARY(64) DEFAULT NULL, -- hash of installed content
  created_at      BINARY(14) DEFAULT NULL,
  updated_at      BINARY(14) DEFAULT NULL,
  PRIMARY KEY (page_id),
  UNIQUE KEY uq_pack_name (pack_id, name),
  UNIQUE KEY uq_pack_final_title (pack_id, final_title),
  CONSTRAINT fk_page_pack_id FOREIGN KEY (pack_id)
    REFERENCES /*_*/labki_pack (pack_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) /*$wgDBTableOptions*/;


-- ===========================================================
--  Helpful indexes
-- ===========================================================

-- Core relational indexes
CREATE INDEX /*i*/idx_labki_pack_repo           ON /*_*/labki_pack (content_repo_id);
CREATE INDEX /*i*/idx_labki_page_pack           ON /*_*/labki_page (pack_id);
CREATE INDEX /*i*/idx_labki_page_final_title    ON /*_*/labki_page (final_title);
CREATE INDEX /*i*/idx_labki_page_wiki_page_id   ON /*_*/labki_page (wiki_page_id);

-- New helpful indexes for multi-ref repos
CREATE INDEX /*i*/idx_labki_repo_url_ref        ON /*_*/labki_content_repo (content_repo_url, source_ref);
CREATE INDEX /*i*/idx_labki_repo_name           ON /*_*/labki_content_repo (content_repo_name);
CREATE INDEX /*i*/idx_labki_repo_last_commit    ON /*_*/labki_content_repo (last_commit);
