-- TAMASYA V137 OPTIONAL GROWTH SUITE
-- SAFE ADDITIVE MIGRATION: does not ALTER/DROP/DELETE any core table.
-- Apply only after a verified backup and staging test.
-- Keep TAMASYA_GROWTH_SUITE_ENABLED=0 until both nodes have the same extension schema.

-- FIX31: pin collation agar konsisten dengan core tables (utf8mb4_general_ci).
-- Tanpa ini, MySQL 8 fresh install membuat tabel dengan utf8mb4_0900_ai_ci
-- dan query yang menggabungkan growth_* dengan core tables gagal
-- 'Illegal mix of collations' (terjadi pada PR-save & loyalty-adjust).
SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS growth_rate_plans (
        id VARCHAR(80) PRIMARY KEY, code VARCHAR(40) NOT NULL, name VARCHAR(150) NOT NULL,
        room_type VARCHAR(100) NULL, base_rate DECIMAL(15,2) NOT NULL DEFAULT 0,
        min_rate DECIMAL(15,2) NOT NULL DEFAULT 0, max_rate DECIMAL(15,2) NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL DEFAULT 'IDR', active TINYINT(1) NOT NULL DEFAULT 1,
        created_by VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by VARCHAR(50) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        version INT NOT NULL DEFAULT 1, UNIQUE KEY uq_growth_rate_plan_code(code),
        INDEX idx_growth_rate_plan_room(room_type,active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_rate_rules (
        id VARCHAR(80) PRIMARY KEY, plan_id VARCHAR(80) NOT NULL, name VARCHAR(150) NOT NULL,
        priority INT NOT NULL DEFAULT 100, condition_json LONGTEXT NOT NULL,
        adjustment_type VARCHAR(20) NOT NULL DEFAULT 'percent', adjustment_value DECIMAL(15,4) NOT NULL DEFAULT 0,
        valid_from DATE NULL, valid_to DATE NULL, active TINYINT(1) NOT NULL DEFAULT 1,
        created_by VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by VARCHAR(50) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        version INT NOT NULL DEFAULT 1, INDEX idx_growth_rate_rule_plan(plan_id,active,priority)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_rate_overrides (
        id VARCHAR(80) PRIMARY KEY, plan_id VARCHAR(80) NOT NULL, stay_date DATE NOT NULL,
        room_type VARCHAR(100) NOT NULL DEFAULT '', rate DECIMAL(15,2) NOT NULL DEFAULT 0,
        min_stay INT NOT NULL DEFAULT 1, stop_sell TINYINT(1) NOT NULL DEFAULT 0,
        closed_to_arrival TINYINT(1) NOT NULL DEFAULT 0, closed_to_departure TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(500) NULL, operation_id VARCHAR(100) NULL, created_by VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        version INT NOT NULL DEFAULT 1, UNIQUE KEY uq_growth_rate_override(plan_id,stay_date,room_type),
        UNIQUE KEY uq_growth_rate_override_operation(operation_id), INDEX idx_growth_rate_override_date(stay_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_companies (
        id VARCHAR(80) PRIMARY KEY, code VARCHAR(40) NOT NULL, name VARCHAR(180) NOT NULL,
        tax_id VARCHAR(80) NULL, billing_email VARCHAR(190) NULL, phone VARCHAR(80) NULL, address TEXT NULL,
        credit_limit DECIMAL(15,2) NOT NULL DEFAULT 0, payment_terms_days INT NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'active', notes TEXT NULL,
        created_by VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by VARCHAR(50) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        version INT NOT NULL DEFAULT 1, UNIQUE KEY uq_growth_company_code(code), INDEX idx_growth_company_status(status,name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_group_reservations (
        id VARCHAR(80) PRIMARY KEY, group_code VARCHAR(50) NOT NULL, company_id VARCHAR(80) NULL,
        name VARCHAR(180) NOT NULL, arrival_date DATE NOT NULL, departure_date DATE NOT NULL,
        room_block_qty INT NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'draft',
        billing_mode VARCHAR(30) NOT NULL DEFAULT 'individual', master_notes TEXT NULL,
        operation_id VARCHAR(100) NULL, created_by VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by VARCHAR(50) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        version INT NOT NULL DEFAULT 1, UNIQUE KEY uq_growth_group_code(group_code), UNIQUE KEY uq_growth_group_operation(operation_id),
        INDEX idx_growth_group_dates(arrival_date,departure_date,status), INDEX idx_growth_group_company(company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_group_booking_links (
        id VARCHAR(80) PRIMARY KEY, group_id VARCHAR(80) NOT NULL, booking_id VARCHAR(100) NOT NULL,
        billing_mode VARCHAR(30) NOT NULL DEFAULT 'individual', routed_percent DECIMAL(7,4) NOT NULL DEFAULT 0,
        notes VARCHAR(500) NULL, operation_id VARCHAR(100) NULL, linked_by VARCHAR(50) NULL,
        linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_growth_group_booking(group_id,booking_id), UNIQUE KEY uq_growth_group_booking_once(booking_id), UNIQUE KEY uq_growth_group_link_operation(operation_id),
        INDEX idx_growth_group_link_booking(booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_vendors (
        id VARCHAR(80) PRIMARY KEY, code VARCHAR(40) NOT NULL, name VARCHAR(180) NOT NULL,
        tax_id VARCHAR(80) NULL, email VARCHAR(190) NULL, phone VARCHAR(80) NULL, address TEXT NULL,
        payment_terms_days INT NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'active', notes TEXT NULL,
        created_by VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by VARCHAR(50) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        version INT NOT NULL DEFAULT 1, UNIQUE KEY uq_growth_vendor_code(code), INDEX idx_growth_vendor_status(status,name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_purchase_orders (
        id VARCHAR(80) PRIMARY KEY, po_number VARCHAR(60) NOT NULL, vendor_id VARCHAR(80) NOT NULL,
        order_date DATE NOT NULL, expected_date DATE NULL, status VARCHAR(30) NOT NULL DEFAULT 'draft',
        subtotal DECIMAL(15,2) NOT NULL DEFAULT 0, tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(15,2) NOT NULL DEFAULT 0, currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
        notes TEXT NULL, operation_id VARCHAR(100) NULL, created_by VARCHAR(50) NULL,
        approved_by VARCHAR(50) NULL, approved_at DATETIME NULL, received_by VARCHAR(50) NULL, received_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_by VARCHAR(50) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, version INT NOT NULL DEFAULT 1,
        UNIQUE KEY uq_growth_po_number(po_number), UNIQUE KEY uq_growth_po_operation(operation_id),
        INDEX idx_growth_po_vendor(vendor_id,status), INDEX idx_growth_po_date(order_date,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_purchase_order_items (
        id VARCHAR(80) PRIMARY KEY, po_id VARCHAR(80) NOT NULL, inventory_id VARCHAR(100) NULL,
        sku VARCHAR(100) NULL, item_name VARCHAR(190) NOT NULL, quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
        unit VARCHAR(40) NOT NULL DEFAULT 'pcs', unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        tax_rate DECIMAL(9,4) NOT NULL DEFAULT 0, tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(15,2) NOT NULL DEFAULT 0, received_quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
        notes VARCHAR(500) NULL, INDEX idx_growth_po_item_po(po_id), INDEX idx_growth_po_item_inventory(inventory_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_purchase_order_payments (
        id VARCHAR(80) PRIMARY KEY, po_id VARCHAR(80) NOT NULL, transaction_id VARCHAR(100) NOT NULL,
        amount_applied DECIMAL(15,2) NOT NULL DEFAULT 0, operation_id VARCHAR(100) NULL,
        linked_by VARCHAR(50) NULL, linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_growth_po_payment_tx(po_id,transaction_id), UNIQUE KEY uq_growth_po_payment_operation(operation_id),
        INDEX idx_growth_po_payment_po(po_id), INDEX idx_growth_po_payment_tx(transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_channel_mappings (
        id VARCHAR(80) PRIMARY KEY, channel_code VARCHAR(50) NOT NULL, external_property_id VARCHAR(120) NOT NULL DEFAULT '',
        external_room_type VARCHAR(160) NOT NULL, internal_room_type VARCHAR(160) NOT NULL,
        external_rate_plan VARCHAR(160) NOT NULL DEFAULT '', internal_rate_plan_id VARCHAR(80) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1, notes VARCHAR(500) NULL, created_by VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        version INT NOT NULL DEFAULT 1, UNIQUE KEY uq_growth_channel_mapping(channel_code,external_property_id,external_room_type,external_rate_plan),
        INDEX idx_growth_channel_internal(internal_room_type,active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_channel_events (
        id VARCHAR(100) PRIMARY KEY, channel_code VARCHAR(50) NOT NULL, event_type VARCHAR(80) NOT NULL,
        external_id VARCHAR(190) NULL, operation_id VARCHAR(100) NULL, status VARCHAR(30) NOT NULL DEFAULT 'registered',
        payload_hash CHAR(64) NOT NULL, error_message VARCHAR(500) NULL, received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL, created_by VARCHAR(50) NULL,
        UNIQUE KEY uq_growth_channel_operation(operation_id), INDEX idx_growth_channel_event(channel_code,status,received_at),
        INDEX idx_growth_channel_external(channel_code,external_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_payment_intents (
        id VARCHAR(100) PRIMARY KEY, booking_id VARCHAR(100) NOT NULL, provider_code VARCHAR(50) NOT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'created',
        external_reference VARCHAR(190) NULL, operation_id VARCHAR(100) NULL, expires_at DATETIME NULL,
        confirmed_transaction_id VARCHAR(100) NULL, notes VARCHAR(500) NULL, created_by VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_growth_payment_operation(operation_id), INDEX idx_growth_payment_booking(booking_id,status),
        INDEX idx_growth_payment_external(provider_code,external_reference)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_payment_events (
        id VARCHAR(100) PRIMARY KEY, intent_id VARCHAR(100) NOT NULL, provider_event_id VARCHAR(190) NULL,
        status VARCHAR(40) NOT NULL, amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        payload_hash CHAR(64) NOT NULL, signature_valid TINYINT(1) NOT NULL DEFAULT 0,
        event_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_growth_payment_provider_event(provider_event_id), INDEX idx_growth_payment_event_intent(intent_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations(version,description,applied_at) VALUES ('2026.08.10.v137-optional-growth-suite.1','Optional additive Growth Suite tables; core schema and property data unchanged',CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE description=VALUES(description);

-- Jaminan ganda: samakan collation semua tabel modul ini dengan core tables.
ALTER TABLE `growth_rate_plans` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_rate_rules` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_rate_overrides` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_companies` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_group_reservations` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_group_booking_links` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_vendors` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_purchase_orders` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_purchase_order_items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_purchase_order_payments` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_channel_mappings` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_channel_events` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_payment_intents` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_payment_events` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
