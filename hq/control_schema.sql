CREATE TABLE IF NOT EXISTS hq_companies (
 id varchar(80) COLLATE utf8mb4_bin PRIMARY KEY,
 name varchar(190) NOT NULL,
 enabled tinyint unsigned NOT NULL DEFAULT 1,
 created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS hq_properties (
 company_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 property_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 secret_cipher text NOT NULL,
 enabled tinyint unsigned NOT NULL DEFAULT 1,
 PRIMARY KEY(company_id,property_id),
 FOREIGN KEY(company_id) REFERENCES hq_companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS hq_principals (
 id varchar(80) COLLATE utf8mb4_bin PRIMARY KEY,
 token_hash char(64) COLLATE ascii_bin NOT NULL UNIQUE,
 role varchar(32) COLLATE ascii_bin NOT NULL,
 company_id varchar(80) COLLATE utf8mb4_bin NULL,
 property_ids text NOT NULL,
 enabled tinyint unsigned NOT NULL DEFAULT 1,
 FOREIGN KEY(company_id) REFERENCES hq_companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS hq_control_audit (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 actor_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 operation_id varchar(100) COLLATE ascii_bin NOT NULL,
 company_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 command varchar(32) COLLATE ascii_bin NOT NULL,
 payload_hash char(64) COLLATE ascii_bin NOT NULL,
 result_json text NOT NULL,
 created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY actor_operation(actor_id,operation_id),
 KEY company_audit(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
