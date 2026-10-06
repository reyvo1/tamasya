-- TAMASYA V137 R4 OPERATIONAL DOMAIN CONSOLIDATION
-- Target: existing R3 MariaDB databases only. Fresh installs MUST use database_setup.sql.
-- Runtime never executes this file automatically.
-- IMPORTANT: MariaDB DDL (ALTER/CREATE) performs implicit commits. This file is therefore
-- intentionally NOT advertised as atomic. Take a verified backup first, stop writes during
-- the maintenance window, run the migration, then run the post-import checks. The statements
-- use IF NOT EXISTS / idempotent backfill patterns so an interrupted migration can be inspected
-- and safely re-run after the underlying issue is corrected.


ALTER TABLE `maintenance_tickets`
  ADD COLUMN IF NOT EXISTS `work_type` varchar(40) NOT NULL DEFAULT 'corrective' AFTER `assigned_to`,
  ADD COLUMN IF NOT EXISTS `origin_type` varchar(50) NOT NULL DEFAULT 'manual' AFTER `work_type`,
  ADD COLUMN IF NOT EXISTS `origin_id` varchar(100) DEFAULT NULL AFTER `origin_type`,
  ADD COLUMN IF NOT EXISTS `resolution_note` text DEFAULT NULL AFTER `actual_cost`,
  ADD COLUMN IF NOT EXISTS `cancel_reason` text DEFAULT NULL AFTER `resolution_note`;

ALTER TABLE `room_vacancy_reports`
  ADD COLUMN IF NOT EXISTS `belongings_detail` text DEFAULT NULL AFTER `belongings_status`,
  ADD COLUMN IF NOT EXISTS `damage_detail` text DEFAULT NULL AFTER `damage_status`;

ALTER TABLE `system_alerts`
  ADD COLUMN IF NOT EXISTS `entity_type` varchar(80) DEFAULT NULL AFTER `message`,
  ADD COLUMN IF NOT EXISTS `entity_id` varchar(190) DEFAULT NULL AFTER `entity_type`;

ALTER TABLE `maintenance_tickets`
  ADD INDEX IF NOT EXISTS `idx_mt_origin` (`origin_type`,`origin_id`);

ALTER TABLE `system_alerts`
  ADD INDEX IF NOT EXISTS `idx_alert_entity` (`entity_type`,`entity_id`);

CREATE TABLE IF NOT EXISTS `lost_found_items` (
  `id` varchar(80) NOT NULL,
  `alert_id` varchar(80) DEFAULT NULL,
  `booking_id` varchar(100) DEFAULT NULL,
  `room_number` varchar(50) NOT NULL,
  `item_description` text NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'operational',
  `source_id` varchar(100) DEFAULT NULL,
  `custody_status` varchar(30) NOT NULL DEFAULT 'found',
  `storage_location` varchar(190) DEFAULT NULL,
  `custody_tag` varchar(100) DEFAULT NULL,
  `guest_notified_at` datetime DEFAULT NULL,
  `guest_notified_by` varchar(50) DEFAULT NULL,
  `resolution` varchar(30) DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `returned_to` varchar(190) DEFAULT NULL,
  `disposed_at` datetime DEFAULT NULL,
  `found_at` datetime NOT NULL DEFAULT current_timestamp(),
  `found_by` varchar(50) DEFAULT NULL,
  `found_by_name` varchar(150) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lost_found_alert` (`alert_id`),
  KEY `idx_lost_found_room_status` (`room_number`,`custody_status`),
  KEY `idx_lost_found_booking` (`booking_id`,`created_at`),
  KEY `idx_lost_found_source` (`source_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `lost_found_items`
  ADD COLUMN IF NOT EXISTS `notes` text DEFAULT NULL AFTER `resolution_note`,
  ADD COLUMN IF NOT EXISTS `returned_at` datetime DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `returned_to` varchar(190) DEFAULT NULL AFTER `returned_at`,
  ADD COLUMN IF NOT EXISTS `disposed_at` datetime DEFAULT NULL AFTER `returned_to`;

CREATE TABLE IF NOT EXISTS `maintenance_cancellation_reviews` (
  `id` varchar(80) NOT NULL,
  `alert_id` varchar(80) DEFAULT NULL,
  `ticket_id` varchar(80) DEFAULT NULL,
  `room_number` varchar(50) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `decision` varchar(40) DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'maintenance-cancelled',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_by` varchar(50) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_maintenance_cancel_review_alert` (`alert_id`),
  UNIQUE KEY `uq_maintenance_cancel_review_ticket` (`ticket_id`),
  KEY `idx_maintenance_cancel_review_room` (`room_number`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `operational_entity_links` (
  `id` varchar(80) NOT NULL,
  `from_entity_type` varchar(80) NOT NULL,
  `from_entity_id` varchar(190) NOT NULL,
  `to_entity_type` varchar(80) NOT NULL,
  `to_entity_id` varchar(190) NOT NULL,
  `relation` varchar(80) NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_operational_entity_relation` (`from_entity_type`,`from_entity_id`,`to_entity_type`,`to_entity_id`,`relation`),
  KEY `idx_operational_link_from` (`from_entity_type`,`from_entity_id`),
  KEY `idx_operational_link_to` (`to_entity_type`,`to_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `operational_incidents` (
  `id` varchar(80) NOT NULL,
  `incident_type` varchar(120) NOT NULL,
  `description` text NOT NULL,
  `severity` varchar(30) NOT NULL DEFAULT 'warning',
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `room_number` varchar(50) DEFAULT NULL,
  `area_ref` varchar(190) DEFAULT NULL,
  `blocks_room` tinyint(1) NOT NULL DEFAULT 0,
  `hold_id` varchar(80) DEFAULT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'manual',
  `source_id` varchar(100) DEFAULT NULL,
  `reported_by` varchar(50) DEFAULT NULL,
  `reported_by_name` varchar(150) DEFAULT NULL,
  `reported_at` datetime NOT NULL DEFAULT current_timestamp(),
  `assigned_to` varchar(50) DEFAULT NULL,
  `acknowledged_by` varchar(50) DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `resolved_by` varchar(50) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_operational_incident_status` (`status`,`severity`,`reported_at`),
  KEY `idx_operational_incident_room` (`room_number`,`status`),
  KEY `idx_operational_incident_source` (`source_type`,`source_id`),
  KEY `idx_operational_incident_hold` (`hold_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE IF NOT EXISTS `guest_service_requests` (
  `id` varchar(80) NOT NULL,
  `booking_id` varchar(100) DEFAULT NULL,
  `room_number` varchar(50) DEFAULT NULL,
  `request_type` varchar(120) NOT NULL,
  `description` text NOT NULL,
  `priority` varchar(30) NOT NULL DEFAULT 'normal',
  `department` varchar(80) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `assigned_to` varchar(50) DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'operations_center',
  `source_id` varchar(100) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `fulfilled_by` varchar(50) DEFAULT NULL,
  `fulfilled_at` datetime DEFAULT NULL,
  `cancelled_by` varchar(50) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guest_service_source` (`source_type`,`source_id`),
  KEY `idx_guest_service_booking` (`booking_id`,`status`),
  KEY `idx_guest_service_room` (`room_number`,`status`),
  KEY `idx_guest_service_queue` (`status`,`priority`,`created_at`),
  KEY `idx_guest_service_assignee` (`assigned_to`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `room_operational_holds` (
  `id` varchar(80) NOT NULL,
  `room_number` varchar(50) NOT NULL,
  `hold_type` varchar(80) NOT NULL,
  `severity` varchar(30) NOT NULL DEFAULT 'critical',
  `reason` text NOT NULL,
  `source_entity_type` varchar(80) DEFAULT NULL,
  `source_entity_id` varchar(190) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `released_by` varchar(50) DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `release_note` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_room_hold_open` (`room_number`,`status`),
  KEY `idx_room_hold_source` (`source_entity_type`,`source_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Preserve legacy data while separating structured fields.
UPDATE `room_vacancy_reports`
   SET `belongings_detail`=CASE WHEN `belongings_status`='found' THEN NULLIF(TRIM(`notes`),'') ELSE `belongings_detail` END
 WHERE `belongings_status`='found' AND (`belongings_detail` IS NULL OR TRIM(`belongings_detail`)='');
UPDATE `room_vacancy_reports`
   SET `damage_detail`=CASE WHEN `damage_status`='found' THEN NULLIF(TRIM(`notes`),'') ELSE `damage_detail` END
 WHERE `damage_status`='found' AND (`damage_detail` IS NULL OR TRIM(`damage_detail`)='');

UPDATE `maintenance_tickets`
   SET `origin_type`=CASE
       WHEN `id` LIKE 'ticket_hk_%' THEN 'housekeeping'
       WHEN `id` LIKE 'ticket_checkout_damage_%' THEN 'vacancy_checkout'
       ELSE COALESCE(NULLIF(`origin_type`,''),'manual') END;

-- Legacy Lost & Found alerts become domain records. Alert remains notification surface.
INSERT IGNORE INTO `lost_found_items`
(`id`,`alert_id`,`room_number`,`item_description`,`source_type`,`source_id`,`custody_status`,`found_at`,`created_at`,`updated_at`)
SELECT CONCAT('lf_',SUBSTRING(SHA2(`id`,256),1,48)),`id`,SUBSTRING_INDEX(`source`,':',-1),
       CASE WHEN TRIM(`message`)<>'' THEN `message` ELSE 'Temuan Lost & Found legacy' END,
       'legacy_alert',`id`,
       CASE WHEN `source` LIKE 'lost-found-custody:%' THEN 'secured' ELSE 'found' END,
       `created_at`,`created_at`,`created_at`
  FROM `system_alerts`
 WHERE `source` LIKE 'lost-found:%' OR `source` LIKE 'lost-found-custody:%';

UPDATE `system_alerts` sa
JOIN `lost_found_items` lf ON lf.alert_id=sa.id
   SET sa.entity_type='lost_found_item',sa.entity_id=lf.id
 WHERE sa.entity_type IS NULL OR sa.entity_type='';

-- Legacy cancellation alerts become review domain records. ticket_id can be reconciled later
-- from the deterministic alert id if it was not recoverable from historical content.
INSERT IGNORE INTO `maintenance_cancellation_reviews`
(`id`,`alert_id`,`ticket_id`,`room_number`,`status`,`decision`,`resolution_note`,`source`,`created_at`,`resolved_by`,`resolved_at`,`updated_at`)
SELECT CONCAT('mcr_',SUBSTRING(SHA2(`id`,256),1,48)),`id`,NULL,SUBSTRING_INDEX(`source`,':',-1),
       CASE WHEN `acknowledged_at` IS NULL THEN 'open' ELSE 'resolved' END,
       CASE WHEN `acknowledged_at` IS NULL THEN NULL ELSE 'legacy_acknowledged' END,
       CASE WHEN `acknowledged_at` IS NULL THEN NULL ELSE 'Migrated from legacy acknowledged cancellation alert.' END,
       'legacy_alert',`created_at`,`acknowledged_by`,`acknowledged_at`,`created_at`
  FROM `system_alerts`
 WHERE `source` LIKE 'maintenance-cancelled:%';

UPDATE `system_alerts` sa
JOIN `maintenance_cancellation_reviews` mr ON mr.alert_id=sa.id
   SET sa.entity_type='maintenance_cancellation_review',sa.entity_id=mr.id
 WHERE sa.entity_type IS NULL OR sa.entity_type='';

-- Release identity is updated only after all structural/backfill statements above succeed.
UPDATE `schema_release_state`
   SET `current_release`='V137_FRESH_CANONICAL_MULTI_HOTEL',
       `patch_level`='V137_STABLE_HARDENED_R4_OPERATIONAL_DOMAIN_CONSOLIDATION_R1_20260815',
       `migration_run_id`='v137_r4_operational_domain_consolidation_r1',
       `maintenance_required`=0,
       `updated_at`=CURRENT_TIMESTAMP
 WHERE `id`='system_default';

INSERT IGNORE INTO `schema_migrations` (`version`,`description`) VALUES
('2026.08.15.v137-r4-operational-domain-consolidation.1','R4 operational domain consolidation migration from R3: structured operational domains, security/safety incidents, flexible guest service requests, and flexible incident capture.');

