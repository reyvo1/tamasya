-- TAMASYA V137 R6 Reservation / Check-in Inventory Separation
-- Upgrade path: R5 Guest Service Context Consolidation R1 -> R6
-- Non-destructive: changes booking status DEFAULT only and snapshots deterministic
-- overnight/open-ended schedule windows from the property's current hotel clocks.
-- No booking lifecycle status is rewritten by this migration.

ALTER TABLE `bookings`
  MODIFY COLUMN `status` varchar(20) NOT NULL DEFAULT 'reserved';

-- Stabilize legacy booking inventory windows before future hotel clock changes.
-- Short-time rows are intentionally excluded because their explicit times cannot
-- be reconstructed safely from date-only fields. Existing non-null schedule
-- values are never overwritten.
UPDATE `bookings` b
JOIN `hotel_operational_settings` h ON h.`id`='system_default'
   SET b.`scheduledCheckInAt`=COALESCE(b.`scheduledCheckInAt`,CONCAT(b.`checkIn`,' ',h.`checkin_time`)),
       b.`scheduledCheckOutAt`=CASE
           WHEN COALESCE(b.`isOpenEnded`,0)=1 OR LOWER(COALESCE(b.`stayMode`,''))='open_ended' THEN NULL
           ELSE COALESCE(b.`scheduledCheckOutAt`,CONCAT(b.`checkOut`,' ',h.`checkout_time`))
       END,
       b.`checkoutDueAt`=CASE
           WHEN COALESCE(b.`isOpenEnded`,0)=1 OR LOWER(COALESCE(b.`stayMode`,''))='open_ended' THEN NULL
           ELSE COALESCE(b.`checkoutDueAt`,b.`scheduledCheckOutAt`,CONCAT(b.`checkOut`,' ',h.`checkout_time`))
       END
 WHERE LOWER(COALESCE(NULLIF(TRIM(b.`stayMode`),''),'overnight')) IN ('overnight','open_ended')
   AND (b.`scheduledCheckInAt` IS NULL
        OR (COALESCE(b.`isOpenEnded`,0)=0 AND LOWER(COALESCE(b.`stayMode`,''))<>'open_ended' AND b.`scheduledCheckOutAt` IS NULL)
        OR (COALESCE(b.`isOpenEnded`,0)=0 AND LOWER(COALESCE(b.`stayMode`,''))<>'open_ended' AND b.`checkoutDueAt` IS NULL));

-- Release identity advances only after the safe default/backfill completes.
UPDATE `schema_release_state`
   SET `current_release`='V137_FRESH_CANONICAL_MULTI_HOTEL',
       `patch_level`='V137_STABLE_HARDENED_R6_RESERVATION_CHECKIN_INVENTORY_SEPARATION_R1_20260815',
       `migration_run_id`='v137_r6_reservation_checkin_inventory_separation_r1',
       `maintenance_required`=0,
       `updated_at`=CURRENT_TIMESTAMP
 WHERE `id`='system_default';

INSERT IGNORE INTO `schema_migrations` (`version`,`description`) VALUES
('2026.08.15.v137-r6-reservation-checkin-inventory-separation.1','R6 reservation/check-in inventory separation: booking default is reserved, reservation schedule windows are snapshotted, explicit check-in activates occupancy, and overlap/public inventory uses canonical hotel clocks.');
