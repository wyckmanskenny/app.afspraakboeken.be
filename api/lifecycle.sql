CREATE TABLE IF NOT EXISTS ab_trial_signups (
 email VARCHAR(190) PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 company VARCHAR(120) NOT NULL,
 sector VARCHAR(80) NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_trials (
 tenant_id CHAR(32) PRIMARY KEY,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 upgrade_requested_at DATETIME NULL,
 converted_at DATETIME NULL,
 contact_id CHAR(32) NULL,
 CONSTRAINT ab_trial_tenant FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_trial_mail (
 tenant_id CHAR(32) NOT NULL,
 days_before TINYINT NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'pending',
 attempts TINYINT NOT NULL DEFAULT 0,
 attempted_at DATETIME NULL,
 sent_at DATETIME NULL,
 PRIMARY KEY (tenant_id,days_before),
 CONSTRAINT ab_trial_mail_tenant FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
