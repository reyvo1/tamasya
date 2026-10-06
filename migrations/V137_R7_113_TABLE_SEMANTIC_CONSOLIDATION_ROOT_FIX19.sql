-- TAMASYA V137 R7 FIX19
-- 113-table semantic consolidation successor for staging.
-- Purpose:
--   * remove obsolete inbound Webhook Hub tables with NO replacement tables;
--   * consolidate finance semantic binding into existing categories.system_key;
--   * keep operational room type authority in existing rooms.type;
--   * preserve FIX17 transaction/allocation identity and FIX18 receipt hygiene.

SET @db := DATABASE();

-- If FIX17/FIX18 binding table exists, mirror any active assignment back into
-- categories.system_key before the helper table is removed.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=@db AND table_name='finance_semantic_bindings')=1,
  'UPDATE categories c JOIN finance_semantic_bindings b ON b.category_id=c.id SET c.system_key=b.role_key,c.is_system=1,c.is_active=1 WHERE b.category_id IS NOT NULL AND b.category_id<>''''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Transaction catalog identity: additive and idempotent from FIX15-compatible schema.
ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `categoryId` varchar(50) DEFAULT NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `categorySystemKey` varchar(80) DEFAULT NULL AFTER `categoryId`,
  ADD COLUMN IF NOT EXISTS `subcategoryId` varchar(50) DEFAULT NULL AFTER `subcategory`,
  ADD COLUMN IF NOT EXISTS `subcategorySystemKey` varchar(80) DEFAULT NULL AFTER `subcategoryId`;

UPDATE `transactions` t
JOIN `categories` c ON c.type=t.type AND LOWER(TRIM(c.name))=LOWER(TRIM(t.category))
SET t.categoryId=c.id,t.categorySystemKey=c.system_key
WHERE (t.categoryId IS NULL OR t.categoryId='');

UPDATE `transactions` t
JOIN `categories` c ON c.id=t.categoryId
SET t.categorySystemKey=c.system_key
WHERE t.categoryId IS NOT NULL AND t.categoryId<>''
  AND (t.categorySystemKey IS NULL OR t.categorySystemKey='');

UPDATE `transactions` t
JOIN `subcategories` sc ON sc.category_id=t.categoryId AND LOWER(TRIM(sc.name))=LOWER(TRIM(COALESCE(t.subcategory,'')))
SET t.subcategoryId=sc.id,t.subcategorySystemKey=sc.system_key
WHERE t.categoryId IS NOT NULL AND t.categoryId<>''
  AND TRIM(COALESCE(t.subcategory,''))<>''
  AND (t.subcategoryId IS NULL OR t.subcategoryId='');

UPDATE `transactions` t
JOIN `subcategories` sc ON sc.id=t.subcategoryId
SET t.subcategorySystemKey=sc.system_key
WHERE t.subcategoryId IS NOT NULL AND t.subcategoryId<>''
  AND (t.subcategorySystemKey IS NULL OR t.subcategorySystemKey='');

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='transactions' AND index_name='idx_transactions_category_id')=0,
  'ALTER TABLE `transactions` ADD KEY `idx_transactions_category_id` (`categoryId`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='transactions' AND index_name='idx_transactions_subcategory_id')=0,
  'ALTER TABLE `transactions` ADD KEY `idx_transactions_subcategory_id` (`subcategoryId`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='transactions' AND index_name='idx_transactions_category_semantic')=0,
  'ALTER TABLE `transactions` ADD KEY `idx_transactions_category_semantic` (`categorySystemKey`,`type`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=@db AND table_name='subcategories' AND constraint_name='fk_subcategories_category')=0,
  'ALTER TABLE `subcategories` ADD CONSTRAINT `fk_subcategories_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=@db AND table_name='transactions' AND constraint_name='fk_transactions_category')=0,
  'ALTER TABLE `transactions` ADD CONSTRAINT `fk_transactions_category` FOREIGN KEY (`categoryId`) REFERENCES `categories` (`id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=@db AND table_name='transactions' AND constraint_name='fk_transactions_subcategory')=0,
  'ALTER TABLE `transactions` ADD CONSTRAINT `fk_transactions_subcategory` FOREIGN KEY (`subcategoryId`) REFERENCES `subcategories` (`id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `transaction_allocations`
  ADD COLUMN IF NOT EXISTS `category_id` varchar(50) DEFAULT NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `category_system_key` varchar(80) DEFAULT NULL AFTER `category_id`,
  ADD COLUMN IF NOT EXISTS `subcategory_id` varchar(50) DEFAULT NULL AFTER `subcategory`,
  ADD COLUMN IF NOT EXISTS `subcategory_system_key` varchar(80) DEFAULT NULL AFTER `subcategory_id`;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='transaction_allocations' AND index_name='idx_transaction_allocation_category')=0,
  'ALTER TABLE `transaction_allocations` ADD KEY `idx_transaction_allocation_category` (`category_id`,`category_system_key`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='transaction_allocations' AND index_name='idx_transaction_allocation_subcategory')=0,
  'ALTER TABLE `transaction_allocations` ADD KEY `idx_transaction_allocation_subcategory` (`subcategory_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=@db AND table_name='transaction_allocations' AND constraint_name='fk_transaction_allocation_category')=0,
  'ALTER TABLE `transaction_allocations` ADD CONSTRAINT `fk_transaction_allocation_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=@db AND table_name='transaction_allocations' AND constraint_name='fk_transaction_allocation_subcategory')=0,
  'ALTER TABLE `transaction_allocations` ADD CONSTRAINT `fk_transaction_allocation_subcategory` FOREIGN KEY (`subcategory_id`) REFERENCES `subcategories` (`id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Rebuild canonical write-time identity/tax guard with no hotel business label hardcode.
DROP TRIGGER IF EXISTS `trg_transactions_tax_snapshot_bi`;
CREATE TRIGGER `trg_transactions_tax_snapshot_bi`
BEFORE INSERT ON `transactions`
FOR EACH ROW
SET
  NEW.`categoryId` = COALESCE(NULLIF(NEW.`categoryId`,''),(SELECT c.id FROM categories c WHERE c.type=NEW.`type` AND LOWER(TRIM(c.name))=LOWER(TRIM(NEW.`category`)) AND c.is_active=1 ORDER BY c.id LIMIT 1)),
  NEW.`categorySystemKey` = COALESCE(NULLIF(NEW.`categorySystemKey`,''),(SELECT c.system_key FROM categories c WHERE c.id=COALESCE(NULLIF(NEW.`categoryId`,''),(SELECT c2.id FROM categories c2 WHERE c2.type=NEW.`type` AND LOWER(TRIM(c2.name))=LOWER(TRIM(NEW.`category`)) AND c2.is_active=1 ORDER BY c2.id LIMIT 1)) LIMIT 1)),
  NEW.`subcategoryId` = COALESCE(NULLIF(NEW.`subcategoryId`,''),(SELECT s.id FROM subcategories s WHERE s.category_id=NEW.`categoryId` AND LOWER(TRIM(s.name))=LOWER(TRIM(COALESCE(NEW.`subcategory`,''))) AND s.is_active=1 ORDER BY s.id LIMIT 1)),
  NEW.`subcategorySystemKey` = COALESCE(NULLIF(NEW.`subcategorySystemKey`,''),(SELECT s.system_key FROM subcategories s WHERE s.id=NEW.`subcategoryId` LIMIT 1)),
  NEW.`taxSnapshotStatus` = CASE
    WHEN NEW.`recordOrigin`='historical_import' THEN NEW.`taxSnapshotStatus`
    WHEN NEW.`type`<>'income' AND COALESCE(NEW.`taxSnapshotStatus`,'unresolved')='unresolved' THEN 'not_applicable'
    WHEN NEW.`type`='income' AND (
      NEW.`transactionKind` IN ('security_deposit_received','security_deposit_forfeit','salary_reversal','opening_balance_cash','opening_balance_bank')
      OR NEW.`transactionKind` LIKE '%ota_transfer%'
      OR NEW.`transactionKind` LIKE '%internal_transfer%'
    ) AND COALESCE(NEW.`taxSnapshotStatus`,'unresolved')='unresolved' THEN 'not_applicable'
    ELSE NEW.`taxSnapshotStatus`
  END,
  NEW.`taxSource` = CASE
    WHEN NEW.`recordOrigin`='historical_import' THEN NEW.`taxSource`
    WHEN NEW.`type`<>'income' AND COALESCE(NEW.`taxSource`,'')='' THEN 'not_applicable'
    WHEN NEW.`type`='income' AND (
      NEW.`transactionKind` IN ('security_deposit_received','security_deposit_forfeit','salary_reversal','opening_balance_cash','opening_balance_bank')
      OR NEW.`transactionKind` LIKE '%ota_transfer%'
      OR NEW.`transactionKind` LIKE '%internal_transfer%'
    ) AND COALESCE(NEW.`taxSource`,'')='' THEN 'not_applicable'
    ELSE NEW.`taxSource`
  END;

-- Remove FIX17/FIX18 helper structures. No replacement tables are created.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=@db AND table_name='rooms' AND constraint_name='fk_rooms_room_type')=1,
  'ALTER TABLE `rooms` DROP FOREIGN KEY `fk_rooms_room_type`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='rooms' AND index_name='idx_rooms_type_id')=1,
  'ALTER TABLE `rooms` DROP INDEX `idx_rooms_type_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE `rooms` DROP COLUMN IF EXISTS `roomTypeId`;

DROP TABLE IF EXISTS `finance_semantic_bindings`;
DROP TABLE IF EXISTS `room_types`;
DROP TABLE IF EXISTS `inbound_webhook_attempts`;
DROP TABLE IF EXISTS `inbound_webhook_events`;

-- Keep FIX18 receipt cleanup support.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='request_operation_receipts' AND index_name='idx_request_receipt_created')=0,
  'ALTER TABLE `request_operation_receipts` ADD KEY `idx_request_receipt_created` (`created_at`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `schema_migrations` (`version`,`description`)
VALUES ('2026.08.17.v137-r7-113-table-semantic-consolidation-root-fix19.1',
        'FIX19 staging successor: 113-table canonical core; removes obsolete inbound Webhook Hub tables with no replacement tables, stores finance semantic binding in categories.system_key, and keeps room type authority in rooms.type.')
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`);
