-- TAMASYA V137 R7 successor core migration
-- FIX17: full dynamic finance catalog + semantic binding authority + room-type decoupling.
-- Additive/non-destructive for existing staging databases. Existing labels are retained;
-- business behavior is bound explicitly by role_key, never guessed from label text.

CREATE TABLE IF NOT EXISTS `finance_semantic_bindings` (
  `role_key` varchar(80) NOT NULL,
  `transaction_type` varchar(20) NOT NULL,
  `category_id` varchar(50) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`role_key`),
  UNIQUE KEY `uq_finance_semantic_category` (`category_id`),
  KEY `idx_finance_semantic_type` (`transaction_type`,`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `finance_semantic_bindings` (`role_key`,`transaction_type`,`category_id`,`is_required`) VALUES
  ('room_rental','income',NULL,1),
  ('extra_service','income',NULL,0),
  ('payroll_expense','expense',NULL,0),
  ('inventory_expense','expense',NULL,0),
  ('maintenance_expense','expense',NULL,0),
  ('pbjt_settlement','expense',NULL,0),
  ('pos_revenue','income',NULL,0),
  ('pos_refund','expense',NULL,0),
  ('pos_cogs','expense',NULL,0),
  ('pos_cogs_reversal','income',NULL,0)
ON DUPLICATE KEY UPDATE `transaction_type`=VALUES(`transaction_type`),`is_required`=VALUES(`is_required`);

-- Preserve predecessor semantic assignments as initial bindings when stable system_key rows already exist. This uses stable
-- system_key values only; no Indonesian/English business label is inspected.
UPDATE `finance_semantic_bindings` b
JOIN `categories` c ON c.system_key=b.role_key AND c.type=b.transaction_type
SET b.category_id=c.id
WHERE b.category_id IS NULL;

-- Keep system_key/is_system as a compatibility mirror for existing consumers.
UPDATE `categories` c
JOIN `finance_semantic_bindings` b ON b.category_id=c.id
SET c.system_key=b.role_key,c.is_system=1,c.is_active=1
WHERE b.category_id IS NOT NULL;

-- Self-contained successor path from FIX15: transaction catalog identity was not
-- present in the FIX15 baseline. Add it here instead of requiring FIX16 first.
ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `categoryId` varchar(50) DEFAULT NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `categorySystemKey` varchar(80) DEFAULT NULL AFTER `categoryId`,
  ADD COLUMN IF NOT EXISTS `subcategoryId` varchar(50) DEFAULT NULL AFTER `subcategory`,
  ADD COLUMN IF NOT EXISTS `subcategorySystemKey` varchar(80) DEFAULT NULL AFTER `subcategoryId`;

-- Deterministic exact-name backfill only. No fuzzy/keyword mapping is allowed.
-- Unmatched snapshots remain NULL and keep their historical display text intact.
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

CREATE TABLE IF NOT EXISTS `room_types` (
  `id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_type_name` (`name`),
  KEY `idx_room_types_active` (`is_active`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `rooms`
  ADD COLUMN IF NOT EXISTS `roomTypeId` varchar(50) DEFAULT NULL AFTER `type`;

-- Deterministic room-type master backfill from the operational Room master itself,
-- never from finance subcategories.
INSERT IGNORE INTO `room_types` (`id`,`name`,`is_active`)
SELECT CONCAT('rt_',LEFT(SHA2(LOWER(TRIM(r.type)),256),40)),TRIM(r.type),1
FROM `rooms` r
WHERE TRIM(COALESCE(r.type,''))<>''
GROUP BY LOWER(TRIM(r.type)),TRIM(r.type);

UPDATE `rooms` r
JOIN `room_types` rt ON LOWER(TRIM(rt.name))=LOWER(TRIM(r.type))
SET r.roomTypeId=rt.id
WHERE r.roomTypeId IS NULL OR r.roomTypeId='';

SET @db := DATABASE();

-- Relational integrity for property-owned finance master and transaction identity.
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

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='rooms' AND index_name='idx_rooms_type_id')=0,
  'ALTER TABLE `rooms` ADD KEY `idx_rooms_type_id` (`roomTypeId`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add relational guards only when the predecessor schema does not already have them.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=@db AND table_name='finance_semantic_bindings' AND constraint_name='fk_finance_semantic_category')=0,
  'ALTER TABLE `finance_semantic_bindings` ADD CONSTRAINT `fk_finance_semantic_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=@db AND table_name='rooms' AND constraint_name='fk_rooms_room_type')=0,
  'ALTER TABLE `rooms` ADD CONSTRAINT `fk_rooms_room_type` FOREIGN KEY (`roomTypeId`) REFERENCES `room_types` (`id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Allocation ledger also carries catalog identity. This prevents a booking receipt
-- from losing its POS/extra/room semantic when it is split into accounting segments.
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

-- Existing finance subcategories remain ordinary property-owned master data. Room types
-- are no longer special-cased or protected merely because the parent has room_rental.

INSERT INTO `schema_migrations` (`version`,`description`)
VALUES ('2026.08.17.v137-r7-full-dynamic-finance-catalog-root-fix17.1',
        'FIX17 staging successor: explicit finance semantic bindings, no seeded hotel category labels on fresh install, room types decoupled from finance subcategories, and DB-owned dynamic catalog authority.')
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`);

-- Rebuild the canonical BEFORE INSERT transaction guard so every source path
-- captures master identity at write-time even when an older caller only sends
-- the DB-owned display snapshot. This contains no business-name hardcode.
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

-- FIX18 database hygiene successor.
-- These two generic Webhook Hub tables were staging-only additions and are not
-- referenced by the TAMASYA FIX17/FIX18 runtime. The canonical communication
-- webhook table remains `communication_webhook_events` and MUST NOT be dropped.
DROP TABLE IF EXISTS `inbound_webhook_attempts`;
DROP TABLE IF EXISTS `inbound_webhook_events`;

SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='request_operation_receipts' AND index_name='idx_request_receipt_created')=0,
  'ALTER TABLE `request_operation_receipts` ADD KEY `idx_request_receipt_created` (`created_at`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `schema_migrations` (`version`,`description`)
VALUES ('2026.08.17.v137-r7-full-dynamic-catalog-database-hygiene-root-fix18.1',
        'FIX18 staging successor: FIX17 dynamic finance catalog plus removal of unused inbound Webhook Hub tables and request receipt storage hygiene.')
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`);
