CREATE TABLE IF NOT EXISTS ab_tenants (
 id CHAR(32) PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 sector VARCHAR(80) NOT NULL DEFAULT '',
 contact_email VARCHAR(190) NOT NULL,
 active TINYINT NOT NULL DEFAULT 0,
 plan_type VARCHAR(16) NOT NULL DEFAULT 'monthly',
 monthly_cents INT UNSIGNED NOT NULL DEFAULT 0,
 lifetime_cents INT UNSIGNED NOT NULL DEFAULT 0,
 billing_json LONGTEXT NOT NULL,
 settings_json LONGTEXT NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_users (
 id CHAR(32) PRIMARY KEY,
 tenant_id CHAR(32) NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 name VARCHAR(120) NOT NULL,
 role VARCHAR(16) NOT NULL,
 password_hash VARCHAR(255) NULL,
 active TINYINT NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT ab_user_tenant FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_sessions (
 token_hash CHAR(64) PRIMARY KEY,
 user_id CHAR(32) NOT NULL,
 csrf CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL,
 last_seen DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 CONSTRAINT ab_session_user FOREIGN KEY (user_id) REFERENCES ab_users(id) ON DELETE CASCADE,
 INDEX ab_session_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_invites (
 token_hash CHAR(64) PRIMARY KEY,
 user_id CHAR(32) NOT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 CONSTRAINT ab_invite_user FOREIGN KEY (user_id) REFERENCES ab_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_services (
 id CHAR(32) PRIMARY KEY,
 tenant_id CHAR(32) NOT NULL,
 name VARCHAR(120) NOT NULL,
 category VARCHAR(80) NOT NULL DEFAULT '',
 description TEXT NOT NULL,
 duration SMALLINT UNSIGNED NOT NULL,
 buffer SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 price_cents INT UNSIGNED NOT NULL DEFAULT 0,
 active TINYINT NOT NULL DEFAULT 1,
 image_url VARCHAR(1000) NOT NULL DEFAULT '',
 video_url VARCHAR(1000) NOT NULL DEFAULT '',
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 CONSTRAINT ab_service_tenant FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id),
 INDEX ab_service_tenant_idx(tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_bookings (
 id CHAR(32) PRIMARY KEY,
 tenant_id CHAR(32) NOT NULL,
 service_id CHAR(32) NOT NULL,
 service_name VARCHAR(120) NOT NULL,
 duration SMALLINT UNSIGNED NOT NULL,
 buffer SMALLINT UNSIGNED NOT NULL,
 price_cents INT UNSIGNED NOT NULL,
 booking_date DATE NOT NULL,
 booking_time CHAR(5) NOT NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 customer_name VARCHAR(120) NOT NULL,
 customer_email VARCHAR(190) NOT NULL,
 customer_phone VARCHAR(40) NOT NULL DEFAULT '',
 customer_address VARCHAR(250) NOT NULL DEFAULT '',
 customer_notes TEXT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'confirmed',
 stage VARCHAR(16) NOT NULL DEFAULT 'new',
 source VARCHAR(100) NOT NULL DEFAULT 'website',
 campaign VARCHAR(150) NOT NULL DEFAULT '',
 marketing_consent TINYINT NOT NULL DEFAULT 0,
 request_key VARCHAR(64) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT ab_booking_tenant FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id),
 CONSTRAINT ab_booking_service FOREIGN KEY (service_id) REFERENCES ab_services(id),
 UNIQUE KEY ab_booking_request(tenant_id,request_key),
 INDEX ab_booking_overlap(tenant_id,status,starts_at,ends_at),
 INDEX ab_booking_date(tenant_id,booking_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_contacts (
 id CHAR(32) PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 company VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL,
 phone VARCHAR(40) NOT NULL DEFAULT '',
 sector VARCHAR(80) NOT NULL DEFAULT '',
 message TEXT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'new',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX ab_contact_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_rate_limits (
 bucket CHAR(64) PRIMARY KEY,
 hits INT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL,
 INDEX ab_rate_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id CHAR(32) NULL,
 action VARCHAR(80) NOT NULL,
 target_id VARCHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
