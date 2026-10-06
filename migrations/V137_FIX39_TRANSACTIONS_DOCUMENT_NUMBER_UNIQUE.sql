-- FIX39: enforce uniqueness of ledger document numbers on `transactions`.
-- tamasyaEnsureTransactionDocumentNumber fills empty values at creation time,
-- but legacy rows may still share the same documentNumber. Before adding the
-- UNIQUE KEY, collapse duplicate non-empty values deterministically: the
-- lowest id keeps the original value, every other row gets a -DUP<id> suffix.
-- Empty strings are normalized to NULL (the schema default) so multiple rows
-- without a document number stay legal under the unique index.
SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

UPDATE transactions SET documentNumber = NULL WHERE documentNumber = '';

UPDATE transactions t
JOIN (
    SELECT documentNumber AS dn, MIN(id) AS keep_id
    FROM transactions
    WHERE documentNumber IS NOT NULL AND documentNumber <> ''
    GROUP BY documentNumber
    HAVING COUNT(*) > 1
) d ON t.documentNumber = d.dn AND t.id <> d.keep_id
SET t.documentNumber = CONCAT(t.documentNumber, '-DUP', t.id);

-- Fails loudly if a -DUP<id> value happens to collide with an existing
-- document number; in that case resolve the collision manually and re-run
-- only the ALTER statement.
ALTER TABLE transactions
  ADD UNIQUE KEY `uniq_transactions_document_number` (`documentNumber`);
