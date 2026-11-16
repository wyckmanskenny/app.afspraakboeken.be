CREATE TABLE IF NOT EXISTS ab_service_images (
 id CHAR(32) PRIMARY KEY,
 tenant_id CHAR(32) NOT NULL,
 mime_type VARCHAR(24) NOT NULL,
 byte_count INT UNSIGNED NOT NULL,
 content_hash CHAR(64) NOT NULL,
 image_data MEDIUMBLOB NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY tenant_image_hash(tenant_id,content_hash),
 FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
