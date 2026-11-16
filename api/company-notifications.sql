CREATE TABLE IF NOT EXISTS ab_company_notifications (
 kind VARCHAR(12) NOT NULL,
 object_id CHAR(32) NOT NULL,
 tenant_id CHAR(32) NOT NULL,
 recipient VARCHAR(190) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 attempted_at DATETIME NULL,
 sent_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(kind,object_id),
 INDEX company_mail_due(status,next_attempt_at),
 INDEX company_mail_tenant(tenant_id,status),
 FOREIGN KEY(tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
