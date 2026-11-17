CREATE TABLE IF NOT EXISTS ab_board_reads (
 user_id CHAR(32) NOT NULL,
 tenant_id CHAR(32) NOT NULL,
 kind VARCHAR(12) NOT NULL,
 object_id CHAR(32) NOT NULL,
 seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,tenant_id,kind,object_id),
 FOREIGN KEY(user_id) REFERENCES ab_users(id),
 FOREIGN KEY(tenant_id) REFERENCES ab_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
