CREATE TABLE IF NOT EXISTS /*_*/labki_content_repo (
  content_repo_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_repo_url VARBINARY(512) NOT NULL,
  content_repo_name VARBINARY(255) DEFAULT NULL,
  default_ref VARBINARY(255) DEFAULT NULL,
  created_at BINARY(14) DEFAULT NULL,
  updated_at BINARY(14) DEFAULT NULL,
  PRIMARY KEY (content_repo_id),
  UNIQUE KEY uq_content_repo_url (content_repo_url)
) /*$wgDBTableOptions*/;

CREATE TABLE IF NOT EXISTS /*_*/labki_pack (
  pack_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_repo_id INT UNSIGNED NOT NULL,
  name VARBINARY(255) NOT NULL,
  version VARBINARY(64) DEFAULT NULL,
  source_ref VARBINARY(255) DEFAULT NULL,
  source_commit VARBINARY(64) DEFAULT NULL,
  installed_at BINARY(14) DEFAULT NULL,
  installed_by INT UNSIGNED DEFAULT NULL,
  updated_at BINARY(14) DEFAULT NULL,
  status VARBINARY(16) DEFAULT 'installed',
  PRIMARY KEY (pack_id),
  UNIQUE KEY uq_content_repo_name_version (content_repo_id, name, version),
  CONSTRAINT fk_pack_content_repo_id FOREIGN KEY (content_repo_id) REFERENCES /*_*/labki_content_repo (content_repo_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pack_installed_by FOREIGN KEY (installed_by) REFERENCES /*_*/user (user_id) ON DELETE SET NULL ON UPDATE CASCADE
) /*$wgDBTableOptions*/;

CREATE TABLE IF NOT EXISTS /*_*/labki_page (
  page_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pack_id INT UNSIGNED NOT NULL,
  name VARBINARY(512) NOT NULL,
  final_title VARBINARY(512) NOT NULL,
  page_namespace INT NOT NULL,
  wiki_page_id INT UNSIGNED DEFAULT NULL,
  last_rev_id INT UNSIGNED DEFAULT NULL,
  content_hash VARBINARY(64) DEFAULT NULL,
  created_at BINARY(14) DEFAULT NULL,
  updated_at BINARY(14) DEFAULT NULL,
  PRIMARY KEY (page_id),
  UNIQUE KEY uq_pack_name (pack_id, name),
  UNIQUE KEY uq_pack_final_title (pack_id, final_title),
  CONSTRAINT fk_page_pack_id FOREIGN KEY (pack_id) REFERENCES /*_*/labki_pack (pack_id) ON DELETE CASCADE ON UPDATE CASCADE
) /*$wgDBTableOptions*/;

-- Helpful indexes
CREATE INDEX /*i*/idx_labki_pack_repo ON /*_*/labki_pack (content_repo_id);
CREATE INDEX /*i*/idx_labki_page_pack ON /*_*/labki_page (pack_id);
CREATE INDEX /*i*/idx_labki_page_final_title ON /*_*/labki_page (final_title);
CREATE INDEX /*i*/idx_labki_page_wiki_page_id ON /*_*/labki_page (wiki_page_id);
