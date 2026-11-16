CREATE TABLE IF NOT EXISTS ab_crm_contacts (
 id CHAR(32) PRIMARY KEY, tenant_id CHAR(32) NOT NULL,
 name VARCHAR(120) NOT NULL, company VARCHAR(120) NOT NULL DEFAULT '',
 email VARCHAR(190) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '', phone_key VARCHAR(40) NOT NULL DEFAULT '', address VARCHAR(250) NOT NULL DEFAULT '',
 review_needed TINYINT NOT NULL DEFAULT 0, revision INT UNSIGNED NOT NULL DEFAULT 1,
 request_key VARCHAR(64) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY crm_contact_tenant(id,tenant_id), UNIQUE KEY crm_contact_request(tenant_id,request_key),
 INDEX crm_contact_email(tenant_id,email), INDEX crm_contact_phone(tenant_id,phone_key),
 FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_crm_notes (
 id CHAR(32) PRIMARY KEY, tenant_id CHAR(32) NOT NULL, contact_id CHAR(32) NOT NULL,
 author_id CHAR(32) NOT NULL, content TEXT NOT NULL, request_key VARCHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY crm_note_request(tenant_id,request_key),
 FOREIGN KEY (contact_id,tenant_id) REFERENCES ab_crm_contacts(id,tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_crm_tasks (
 id CHAR(32) PRIMARY KEY, tenant_id CHAR(32) NOT NULL, contact_id CHAR(32) NOT NULL,
 author_id CHAR(32) NOT NULL, title VARCHAR(200) NOT NULL, due_date DATE NOT NULL,
 completed TINYINT NOT NULL DEFAULT 0, revision INT UNSIGNED NOT NULL DEFAULT 1,
 request_key VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY crm_task_request(tenant_id,request_key), INDEX crm_task_due(tenant_id,completed,due_date),
 FOREIGN KEY (contact_id,tenant_id) REFERENCES ab_crm_contacts(id,tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_crm_tags (
 id CHAR(32) PRIMARY KEY, tenant_id CHAR(32) NOT NULL, name VARCHAR(40) NOT NULL,
 UNIQUE KEY crm_tag_name(tenant_id,name), UNIQUE KEY crm_tag_tenant(id,tenant_id),
 FOREIGN KEY (tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_crm_contact_tags (
 tenant_id CHAR(32) NOT NULL, contact_id CHAR(32) NOT NULL, tag_id CHAR(32) NOT NULL,
 PRIMARY KEY (contact_id,tag_id),
 FOREIGN KEY (contact_id,tenant_id) REFERENCES ab_crm_contacts(id,tenant_id),
 FOREIGN KEY (tag_id,tenant_id) REFERENCES ab_crm_tags(id,tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ab_crm_links (
 tenant_id CHAR(32) NOT NULL, contact_id CHAR(32) NOT NULL, record_type VARCHAR(8) NOT NULL, record_id CHAR(32) NOT NULL,
 PRIMARY KEY (tenant_id,record_type,record_id),
 FOREIGN KEY (contact_id,tenant_id) REFERENCES ab_crm_contacts(id,tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
