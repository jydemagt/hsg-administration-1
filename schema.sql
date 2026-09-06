CREATE TABLE IF NOT EXISTS lager_meta (
  meta_key VARCHAR(100) PRIMARY KEY,
  meta_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  token_hash CHAR(64) NOT NULL UNIQUE,
  token_last4 CHAR(4) NOT NULL,
  token_cipher TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS lager_admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(120) NOT NULL UNIQUE,
  display_name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_superadmin TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hsg_admin_module_access (
  admin_id INT UNSIGNED NOT NULL,
  module_id VARCHAR(100) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(admin_id,module_id),
  CONSTRAINT fk_hsg_admin_module_admin FOREIGN KEY(admin_id) REFERENCES lager_admins(id) ON DELETE CASCADE,
  INDEX idx_hsg_admin_module(module_id,can_view)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(120) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_limit(username,ip_hash,attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS lager_locations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_brands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL UNIQUE,
  description TEXT NULL,
  website_url VARCHAR(500) NULL,
  image_search_url VARCHAR(500) NULL,
  logo_path VARCHAR(255) NULL,
  show_in_catalog TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_brand_parent FOREIGN KEY(parent_id) REFERENCES lager_brands(id) ON DELETE SET NULL,
  INDEX idx_brand_parent(parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(220) NOT NULL,
  call_name VARCHAR(180) NULL,
  brand_id INT UNSIGNED NULL,
  category VARCHAR(140) NULL,
  distillery VARCHAR(160) NULL,
  country VARCHAR(100) NULL,
  age_text VARCHAR(80) NULL,
  vintage_year SMALLINT UNSIGNED NULL,
  abv DECIMAL(5,2) NULL,
  bottle_size_cl DECIMAL(6,2) NULL,
  cask_type VARCHAR(220) NULL,
  cask_number VARCHAR(80) NULL,
  bottle_count VARCHAR(80) NULL,
  wholesale_price DECIMAL(10,2) NULL,
  retail_price DECIMAL(10,2) NULL,
  is_new TINYINT(1) NOT NULL DEFAULT 0,
  show_in_catalog TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','inactive','discontinued') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  supplier_name VARCHAR(180) NULL,
  supplier_domain VARCHAR(190) NULL,
  supplier_url TEXT NULL,
  image_path VARCHAR(255) NULL,
  image_source_url TEXT NULL,
  image_checked_at DATETIME NULL,
  image_method ENUM('manual','supplier','search','ai') NULL,
  image_confidence TINYINT UNSIGNED NULL,
  image_ai_note VARCHAR(500) NULL,
  image_validation_score TINYINT UNSIGNED NULL,
  image_validation_status ENUM('verified','flagged','error') NULL,
  image_validation_note VARCHAR(1000) NULL,
  image_validated_at DATETIME NULL,
  image_validation_model VARCHAR(120) NULL,
  image_approval_status ENUM('pending','approved','rejected') NULL,
  image_approved_at DATETIME NULL,
  image_approved_by_admin INT UNSIGNED NULL,
  data_enrichment_score TINYINT UNSIGNED NULL,
  data_enrichment_source VARCHAR(30) NULL,
  data_enrichment_note VARCHAR(1000) NULL,
  data_enriched_at DATETIME NULL,
  quality_approved_at DATETIME NULL,
  quality_approved_by_admin INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_brand FOREIGN KEY(brand_id) REFERENCES lager_brands(id) ON DELETE SET NULL,
  INDEX idx_product_brand(brand_id), INDEX idx_product_status(status), INDEX idx_product_catalog(show_in_catalog), INDEX idx_product_cask_number(cask_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hsg_supplier_import_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  sheet_name VARCHAR(180) NULL,
  rows_detected INT UNSIGNED NOT NULL DEFAULT 0,
  rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
  created_by_admin INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_supplier_import_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS hsg_product_field_exemptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  field_key VARCHAR(80) NOT NULL,
  reason VARCHAR(255) NULL,
  created_by_admin INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_product_quality_exemption(product_id,field_key),
  INDEX idx_quality_exemption_product(product_id),
  CONSTRAINT fk_quality_exemption_product FOREIGN KEY(product_id) REFERENCES lager_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_stock (
  product_id INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(product_id,location_id),
  CONSTRAINT fk_stock_product FOREIGN KEY(product_id) REFERENCES lager_products(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_location FOREIGN KEY(location_id) REFERENCES lager_locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_reservations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  customer_name VARCHAR(180) NULL,
  reference VARCHAR(180) NULL,
  note TEXT NULL,
  status ENUM('reserved','completed','cancelled') NOT NULL DEFAULT 'reserved',
  created_by INT UNSIGNED NULL,
  created_by_admin INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_res_product FOREIGN KEY(product_id) REFERENCES lager_products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_res_location FOREIGN KEY(location_id) REFERENCES lager_locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_res_user FOREIGN KEY(created_by) REFERENCES lager_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_res_admin FOREIGN KEY(created_by_admin) REFERENCES lager_admins(id) ON DELETE SET NULL,
  INDEX idx_res_status(status), INDEX idx_res_product_location(product_id,location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_stock_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NOT NULL,
  change_qty INT NOT NULL,
  balance_after INT NOT NULL,
  movement_type ENUM('set','adjust','import','sale','transfer_in','transfer_out') NOT NULL,
  reference VARCHAR(180) NULL,
  created_by INT UNSIGNED NULL,
  created_by_admin INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mov_product FOREIGN KEY(product_id) REFERENCES lager_products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_mov_location FOREIGN KEY(location_id) REFERENCES lager_locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_mov_user FOREIGN KEY(created_by) REFERENCES lager_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_mov_admin FOREIGN KEY(created_by_admin) REFERENCES lager_admins(id) ON DELETE SET NULL,
  INDEX idx_mov_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- HSG platform core (shared across present and future modules)
CREATE TABLE IF NOT EXISTS hsg_settings (
  setting_key VARCHAR(160) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hsg_module_versions (
  module_id VARCHAR(100) PRIMARY KEY,
  version VARCHAR(40) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_core TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hsg_audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_type ENUM('admin','link','system') NOT NULL,
  actor_id INT UNSIGNED NULL,
  actor_name VARCHAR(160) NOT NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id VARCHAR(100) NULL,
  details_json LONGTEXT NULL,
  ip_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hsg_audit_created(created_at),
  INDEX idx_hsg_audit_entity(entity_type,entity_id),
  INDEX idx_hsg_audit_actor(actor_type,actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hsg_user_module_access (
  user_id INT UNSIGNED NOT NULL,
  module_id VARCHAR(100) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 0,
  can_operate TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,module_id),
  CONSTRAINT fk_hsg_user_module_user FOREIGN KEY(user_id) REFERENCES lager_users(id) ON DELETE CASCADE,
  INDEX idx_hsg_user_module(module_id,can_view)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hsg_backup_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  backup_type ENUM('data','full') NOT NULL,
  destination ENUM('local','onedrive','both') NOT NULL DEFAULT 'local',
  filename VARCHAR(255) NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  checksum_sha256 CHAR(64) NULL,
  status ENUM('running','success','warning','failed') NOT NULL DEFAULT 'running',
  message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_backup_created(created_at),
  INDEX idx_backup_status(status,backup_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS hsg_update_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_from VARCHAR(40) NOT NULL,
  version_to VARCHAR(40) NOT NULL,
  package_name VARCHAR(255) NOT NULL,
  backup_filename VARCHAR(255) NULL,
  status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  files_changed INT UNSIGNED NOT NULL DEFAULT 0,
  message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_update_created(created_at),
  INDEX idx_update_status(status,version_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS lager_image_candidates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  page_url VARCHAR(1000) NULL,
  image_url VARCHAR(1000) NULL,
  confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
  reason VARCHAR(600) NULL,
  provider VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_image_candidates_product (product_id),
  INDEX idx_image_candidates_score (confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_image_rejections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  url_hash CHAR(64) NOT NULL,
  url VARCHAR(1000) NOT NULL,
  reason VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_image_rejection_product_url (product_id,url_hash),
  INDEX idx_image_rejections_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

