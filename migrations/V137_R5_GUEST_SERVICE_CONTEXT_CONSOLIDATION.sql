-- TAMASYA V137 R5 Guest Service Context Consolidation
-- Upgrade path: R4 Operational Domain Consolidation R1 -> R5
-- Non-destructive: adds context columns/indexes and backfills only deterministic booking-linked rows.

ALTER TABLE `guest_service_requests`
  ADD COLUMN IF NOT EXISTS `context_type` varchar(40) NOT NULL DEFAULT 'legacy_unspecified' AFTER `room_number`,
  ADD COLUMN IF NOT EXISTS `guest_name_snapshot` varchar(150) DEFAULT NULL AFTER `context_type`,
  ADD COLUMN IF NOT EXISTS `requester_name` varchar(150) DEFAULT NULL AFTER `guest_name_snapshot`,
  ADD COLUMN IF NOT EXISTS `requester_contact` varchar(190) DEFAULT NULL AFTER `requester_name`,
  ADD COLUMN IF NOT EXISTS `location_label` varchar(150) DEFAULT NULL AFTER `requester_contact`,
  ADD COLUMN IF NOT EXISTS `request_channel` varchar(40) NOT NULL DEFAULT 'staff_recorded' AFTER `location_label`,
  ADD COLUMN IF NOT EXISTS `needed_at` datetime DEFAULT NULL AFTER `request_channel`,
  ADD COLUMN IF NOT EXISTS `context_verified_at` datetime DEFAULT NULL AFTER `needed_at`,
  ADD COLUMN IF NOT EXISTS `context_verified_by` varchar(50) DEFAULT NULL AFTER `context_verified_at`,
  ADD INDEX IF NOT EXISTS `idx_guest_service_context` (`context_type`,`status`,`created_at`),
  ADD INDEX IF NOT EXISTS `idx_guest_service_needed` (`status`,`needed_at`);

-- Deterministic backfill: only records already linked to an existing booking are classified.
-- Unlinked R4 records remain legacy_unspecified and are visible in verifier for manual review;
-- migration never guesses a guest solely from a free-typed room number.
UPDATE `guest_service_requests` g
JOIN `bookings` b ON b.`id`=g.`booking_id`
   SET g.`context_type`=CASE
         WHEN LOWER(TRIM(b.`status`))='active' THEN 'in_house_guest'
         WHEN LOWER(TRIM(b.`status`))='reserved' THEN 'pre_arrival_guest'
         ELSE g.`context_type`
       END,
       g.`room_number`=CASE
         WHEN LOWER(TRIM(b.`status`)) IN ('active','reserved') THEN b.`roomNumber`
         ELSE g.`room_number`
       END,
       g.`guest_name_snapshot`=COALESCE(NULLIF(TRIM(g.`guest_name_snapshot`),''),NULLIF(TRIM(b.`guestName`),'')),
       g.`requester_name`=COALESCE(NULLIF(TRIM(g.`requester_name`),''),NULLIF(TRIM(b.`guestName`),'')),
       g.`request_channel`=COALESCE(NULLIF(TRIM(g.`request_channel`),''),'staff_recorded'),
       g.`context_verified_at`=COALESCE(g.`context_verified_at`,g.`created_at`),
       g.`context_verified_by`=COALESCE(NULLIF(TRIM(g.`context_verified_by`),''),NULLIF(TRIM(g.`created_by`),''),'migration:r5')
 WHERE g.`context_type`='legacy_unspecified'
   AND LOWER(TRIM(b.`status`)) IN ('active','reserved');

-- Normalize nullable/blank channel values without changing lifecycle state.
UPDATE `guest_service_requests`
   SET `request_channel`='staff_recorded'
 WHERE `request_channel` IS NULL OR TRIM(`request_channel`)='';

-- Release identity is advanced only after structural/backfill statements succeed.
UPDATE `schema_release_state`
   SET `current_release`='V137_FRESH_CANONICAL_MULTI_HOTEL',
       `patch_level`='V137_STABLE_HARDENED_R5_GUEST_SERVICE_CONTEXT_CONSOLIDATION_R1_20260815',
       `migration_run_id`='v137_r5_guest_service_context_consolidation_r1',
       `maintenance_required`=0,
       `updated_at`=CURRENT_TIMESTAMP
 WHERE `id`='system_default';

INSERT IGNORE INTO `schema_migrations` (`version`,`description`) VALUES
('2026.08.15.v137-r5-guest-service-context-consolidation.1','R5 guest service context consolidation: canonical in-house/pre-arrival/non-room ownership, verified booking-room linkage, request channel, optional needed-at, and future guest self-service boundary.');
