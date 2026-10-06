-- FIX32: split-payment snapshot columns missing on `transactions`.
-- tamasyaCanonicalTransactionColumns() (025_financial_posting_authority.php)
-- inserts isSplitPayment/splitCashAmount/splitTransferAmount/
-- splitTransferBankAccountId on EVERY canonical transaction insert, but the
-- schema only carried these columns on `bookings`. Result: every manual /
-- offline-sync / checkout transaction failed with SQLSTATE 42S22.
SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

-- MySQL 8.4: no ADD COLUMN IF NOT EXISTS. The previous single multi-column
-- ALTER aborted wholesale (duplicate column 1060 on databases that already
-- carry partial split columns, or 1072 when the `inputDelayDays` anchor is
-- absent), so the remaining columns stayed missing: backfill split rows then
-- saved without their split snapshot and posted as one cash entry (QRIS) or
-- crashed on a null bank-account reference (transfer, "error 0"). Add each
-- column individually, guarded against duplicates, with no AFTER anchor.
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
    AND COLUMN_NAME = 'isSplitPayment') = 0,
  'ALTER TABLE transactions ADD COLUMN `isSplitPayment` tinyint(1) NOT NULL DEFAULT 0',
  'SELECT ''isSplitPayment: already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
    AND COLUMN_NAME = 'splitCashAmount') = 0,
  'ALTER TABLE transactions ADD COLUMN `splitCashAmount` decimal(15,2) DEFAULT NULL',
  'SELECT ''splitCashAmount: already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
    AND COLUMN_NAME = 'splitTransferAmount') = 0,
  'ALTER TABLE transactions ADD COLUMN `splitTransferAmount` decimal(15,2) DEFAULT NULL',
  'SELECT ''splitTransferAmount: already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
    AND COLUMN_NAME = 'splitTransferBankAccountId') = 0,
  'ALTER TABLE transactions ADD COLUMN `splitTransferBankAccountId` varchar(50) DEFAULT NULL',
  'SELECT ''splitTransferBankAccountId: already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE transactions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
