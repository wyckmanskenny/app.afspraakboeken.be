CREATE TABLE IF NOT EXISTS ab_partners (
 id CHAR(32) PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL,
 referral_code CHAR(24) NOT NULL UNIQUE, active TINYINT NOT NULL DEFAULT 1,
 can_create TINYINT NOT NULL DEFAULT 1, can_activate TINYINT NOT NULL DEFAULT 0,
 revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_partner_members (
 user_id CHAR(32) PRIMARY KEY, partner_id CHAR(32) NOT NULL,
 FOREIGN KEY (user_id) REFERENCES ab_users(id), FOREIGN KEY (partner_id) REFERENCES ab_partners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_partner_accounts (
 tenant_id CHAR(32) PRIMARY KEY, partner_id CHAR(32) NOT NULL, source VARCHAR(16) NOT NULL,
 created_by CHAR(32) NULL, request_key VARCHAR(64) NULL, request_hash CHAR(64) NULL,
 activation_requested_at DATETIME NULL, activated_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY partner_request(partner_id,request_key), INDEX partner_accounts(partner_id,created_at),
 FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id), FOREIGN KEY (partner_id) REFERENCES ab_partners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_partner_referrals (
 email VARCHAR(190) PRIMARY KEY, token_hash CHAR(64) NOT NULL UNIQUE, partner_id CHAR(32) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (partner_id) REFERENCES ab_partners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_partner_enquiries (
 contact_id CHAR(32) PRIMARY KEY, partner_id CHAR(32) NOT NULL,
 FOREIGN KEY (contact_id) REFERENCES ab_contacts(id), FOREIGN KEY (partner_id) REFERENCES ab_partners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_partner_commissions (
 id CHAR(32) PRIMARY KEY, partner_id CHAR(32) NOT NULL, tenant_id CHAR(32) NOT NULL,
 invoice_reference VARCHAR(120) NOT NULL, amount_cents INT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'EUR', terms_json LONGTEXT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'review', paid_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY partner_invoice(partner_id,invoice_reference),
 FOREIGN KEY (partner_id) REFERENCES ab_partners(id), FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
