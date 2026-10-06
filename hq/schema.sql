-- Install ONLY in a separate empty HQ database, never the property database.
CREATE TABLE hq_property_locks (
 company_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 property_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 event_revision bigint unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY(company_id,property_id)
) ENGINE=InnoDB;
CREATE TABLE hq_snapshots (
 checksum char(64) COLLATE ascii_bin NOT NULL PRIMARY KEY,
 company_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 property_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 period_from date NOT NULL, period_to date NOT NULL,
 source_revision bigint unsigned NOT NULL,
 body mediumtext NOT NULL,
 received_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY hq_scope_period(company_id,property_id,period_from,period_to)
) ENGINE=InnoDB;
CREATE TABLE hq_heads (
 company_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 property_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 period_from date NOT NULL, period_to date NOT NULL,
 checksum char(64) COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(company_id,property_id,period_from,period_to),
 FOREIGN KEY(checksum) REFERENCES hq_snapshots(checksum)
) ENGINE=InnoDB;
CREATE TABLE hq_receipts (
 company_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 property_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 operation_id varchar(100) COLLATE utf8mb4_bin NOT NULL,
 payload_hash char(64) COLLATE ascii_bin NOT NULL,
 checksum char(64) COLLATE ascii_bin NOT NULL,
 created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(company_id,property_id,operation_id),
 FOREIGN KEY(checksum) REFERENCES hq_snapshots(checksum)
) ENGINE=InnoDB;
CREATE TABLE hq_nonces (
 company_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 property_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 nonce varchar(80) COLLATE ascii_bin NOT NULL,
 created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(company_id,property_id,nonce),
 KEY hq_nonce_expiry(created_at)
) ENGINE=InnoDB;

CREATE TABLE hq_reports (checksum char(64) COLLATE ascii_bin PRIMARY KEY, company_id varchar(80) COLLATE utf8mb4_bin NOT NULL, body mediumtext NOT NULL, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY company_reports(company_id,created_at)) ENGINE=InnoDB;
