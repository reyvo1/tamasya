CREATE TABLE IF NOT EXISTS hq_delivery_jobs (
 job_id char(64) COLLATE ascii_bin PRIMARY KEY,
 company_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 operation_id varchar(100) COLLATE ascii_bin NOT NULL,
 report_id char(64) COLLATE ascii_bin NOT NULL,
 destination_id varchar(80) COLLATE utf8mb4_bin NOT NULL,
 payload_hash char(64) COLLATE ascii_bin NOT NULL,
 payload_json mediumtext NOT NULL,
 status varchar(20) COLLATE ascii_bin NOT NULL,
 attempts int unsigned NOT NULL DEFAULT 0,
 last_error varchar(80) NULL,
 created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY company_operation(company_id,operation_id),
 KEY delivery_poll(status,created_at),
 KEY company_deliveries(company_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
