-- TAMASYA V137 R7 — Cross-Menu State Authority
-- Existing R6 -> R7. Non-destructive: adds one operational policy column only.
-- rooms.status remains a derived projection; no historical booking/financial rows are rewritten.

ALTER TABLE `hotel_operational_settings`
  ADD COLUMN IF NOT EXISTS `require_payment_before_key_issue` tinyint(1) NOT NULL DEFAULT 0
  AFTER `require_key_control`;

INSERT INTO `schema_migrations` (`version`,`description`)
VALUES ('2026.08.15.v137-r7-cross-menu-state-authority.1',
        'R7: canonical room-state authority across menus/offline/Telegram plus configurable payment-before-key policy; financial journal semantics unchanged.')
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`);

UPDATE `schema_release_state`
   SET `current_release`='V137_FRESH_CANONICAL_MULTI_HOTEL',
       `patch_level`='V137_STABLE_HARDENED_R7_CROSS_MENU_STATE_AUTHORITY_R1_20260815',
       `migration_run_id`='v137_r7_cross_menu_state_authority_r1',
       `maintenance_required`=0,
       `updated_at`=CURRENT_TIMESTAMP
 WHERE `id`='system_default';
