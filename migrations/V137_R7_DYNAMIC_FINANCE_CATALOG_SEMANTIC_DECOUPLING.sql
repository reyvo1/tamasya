-- TAMASYA V137 successor core migration
-- Dynamic finance catalog / semantic decoupling.
-- Safe for staging upgrade: additive transaction identity columns, deterministic
-- backfill, and release of old business-specific extra-service seed locks.

ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `categoryId` varchar(50) DEFAULT NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `categorySystemKey` varchar(80) DEFAULT NULL AFTER `categoryId`,
  ADD COLUMN IF NOT EXISTS `subcategoryId` varchar(50) DEFAULT NULL AFTER `subcategory`,
  ADD COLUMN IF NOT EXISTS `subcategorySystemKey` varchar(80) DEFAULT NULL AFTER `subcategoryId`;

-- Backfill only exact category/type matches. No fuzzy guessing is permitted.
UPDATE `transactions` t
JOIN `categories` c
  ON c.type=t.type AND LOWER(TRIM(c.name))=LOWER(TRIM(t.category))
SET t.categoryId=c.id,
    t.categorySystemKey=c.system_key
WHERE (t.categoryId IS NULL OR t.categoryId='');

UPDATE `transactions` t
JOIN `subcategories` s
  ON s.category_id=t.categoryId AND LOWER(TRIM(s.name))=LOWER(TRIM(t.subcategory))
SET t.subcategoryId=s.id,
    t.subcategorySystemKey=s.system_key
WHERE t.subcategory IS NOT NULL AND TRIM(t.subcategory)<>''
  AND t.categoryId IS NOT NULL AND t.categoryId<>''
  AND (t.subcategoryId IS NULL OR t.subcategoryId='');

-- Keep denormalized legacy parent label synchronized with the current master.
UPDATE `subcategories` s
JOIN `categories` c ON c.id=s.category_id
SET s.category_name=c.name
WHERE s.category_name<>c.name;

-- Old FINAL5 extra-service examples are property defaults, not engine invariants.
-- Make them ordinary master rows so staging admins can rename/archive them.
UPDATE `subcategories`
SET is_system=0, system_key=NULL
WHERE system_key IN ('extra_general','extra_breakfast','extra_minibar','extra_laundry','extra_bed','extra_other');

-- Indexes are added only when absent, using INFORMATION_SCHEMA for MariaDB safety.
SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='transactions' AND index_name='idx_transactions_category_id')=0,
  'ALTER TABLE `transactions` ADD KEY `idx_transactions_category_id` (`categoryId`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='transactions' AND index_name='idx_transactions_subcategory_id')=0,
  'ALTER TABLE `transactions` ADD KEY `idx_transactions_subcategory_id` (`subcategoryId`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='transactions' AND index_name='idx_transactions_category_semantic')=0,
  'ALTER TABLE `transactions` ADD KEY `idx_transactions_category_semantic` (`categorySystemKey`,`type`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Record this successor migration independently from the canonical R7 baseline marker.
-- Fresh FIX16 installs already contain the target shape; upgraded staging databases
-- use this row as an auditable apply receipt.
INSERT INTO `schema_migrations` (`version`,`description`)
VALUES ('2026.08.17.v137-r7-dynamic-finance-catalog-semantic-decoupling.1',
        'FIX16 staging successor: database-driven finance category/subcategory labels with stable semantic identity on transactions; no destructive data rewrite.')
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`);
