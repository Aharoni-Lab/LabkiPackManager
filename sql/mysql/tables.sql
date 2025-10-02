CREATE TABLE IF NOT EXISTS /*_*/labki_pack_registry (
  pack_uid VARBINARY(64) NOT NULL,
  pack_id VARBINARY(255) DEFAULT NULL,
  version VARBINARY(64) DEFAULT NULL,
  source_repo VARBINARY(512) DEFAULT NULL,
  source_ref VARBINARY(255) DEFAULT NULL,
  source_commit VARBINARY(64) DEFAULT NULL,
  installed_at BINARY(14) DEFAULT NULL,
  installed_by INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (pack_uid)
) /*$wgDBTableOptions*/;

CREATE TABLE IF NOT EXISTS /*_*/labki_pack_pages (
  pack_uid VARBINARY(64) NOT NULL,
  pack_id VARBINARY(255) DEFAULT NULL,
  page_title VARBINARY(512) NOT NULL,
  page_namespace INT NOT NULL,
  page_id INT UNSIGNED DEFAULT NULL,
  last_rev_id INT UNSIGNED DEFAULT NULL,
  content_hash VARBINARY(64) DEFAULT NULL,
  PRIMARY KEY (pack_uid, page_title)
) /*$wgDBTableOptions*/;

CREATE TABLE IF NOT EXISTS /*_*/labki_page_mapping (
  pack_uid VARBINARY(64) NOT NULL,
  pack_id VARBINARY(255) DEFAULT NULL,
  page_key VARBINARY(512) NOT NULL,
  final_title VARBINARY(512) NOT NULL,
  created_at BINARY(14) DEFAULT NULL,
  PRIMARY KEY (pack_uid, page_key)
) /*$wgDBTableOptions*/;


