CREATE TABLE IF NOT EXISTS ab_outlook_connections (
 tenant_id CHAR(32) PRIMARY KEY, calendar_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE, calendar_name VARCHAR(255) NOT NULL DEFAULT '', calendar_zone VARCHAR(100) NOT NULL DEFAULT 'Europe/Brussels',
 access_token TEXT NOT NULL, refresh_token TEXT NOT NULL, expires_at DATETIME NOT NULL,
 last_pull DATETIME NULL, coverage_end DATE NULL, last_push DATETIME NULL, last_error VARCHAR(80) NOT NULL DEFAULT '',
 FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_outlook_oauth (
 state_hash CHAR(64) PRIMARY KEY, tenant_id CHAR(32) NOT NULL, user_id CHAR(32) NOT NULL, session_hash CHAR(64) NOT NULL, verifier TEXT NOT NULL, claimed TINYINT NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL,
 FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id), FOREIGN KEY (user_id) REFERENCES ab_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_outlook_busy (
 tenant_id CHAR(32) NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL,
 INDEX outlook_busy_time(tenant_id,starts_at,ends_at), FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_outlook_events (
 tenant_id CHAR(32) NOT NULL, calendar_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, object_key VARCHAR(48) NOT NULL, event_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, transaction_id CHAR(32) NOT NULL,
 dirty TINYINT NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, checked_at DATETIME NULL,
 PRIMARY KEY (tenant_id,calendar_id,object_key), INDEX outlook_pending(tenant_id,dirty), FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
