-- TAMASYA V137 OPTIONAL ENTERPRISE COMPLETION
-- SAFE ADDITIVE MIGRATION: no ALTER/DROP/DELETE against core tables.
-- Requires the V137 optional Growth Suite schema first.
-- Apply only after verified backup and staging/UAT on both nodes.

-- FIX31: pin collation agar konsisten dengan core tables (utf8mb4_general_ci).
-- Tanpa ini, MySQL 8 fresh install membuat tabel dengan utf8mb4_0900_ai_ci
-- dan query yang menggabungkan growth_* dengan core tables gagal
-- 'Illegal mix of collations' (terjadi pada PR-save & loyalty-adjust).
SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS growth_folios (
  id VARCHAR(80) PRIMARY KEY,
  folio_number VARCHAR(80) NOT NULL,
  folio_type VARCHAR(30) NOT NULL DEFAULT 'guest',
  booking_id VARCHAR(100) NULL,
  group_id VARCHAR(80) NULL,
  company_id VARCHAR(80) NULL,
  name VARCHAR(190) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
  status VARCHAR(30) NOT NULL DEFAULT 'open',
  notes TEXT NULL,
  operation_id VARCHAR(100) NULL,
  created_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by VARCHAR(50) NULL,
  closed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_growth_folio_number(folio_number),
  UNIQUE KEY uq_growth_folio_operation(operation_id),
  INDEX idx_growth_folio_booking(booking_id,status),
  INDEX idx_growth_folio_group(group_id,status),
  INDEX idx_growth_folio_company(company_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_folio_transaction_allocations (
  id VARCHAR(80) PRIMARY KEY,
  folio_id VARCHAR(80) NOT NULL,
  transaction_id VARCHAR(100) NOT NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  allocation_role VARCHAR(30) NOT NULL DEFAULT 'guest',
  notes VARCHAR(500) NULL,
  operation_id VARCHAR(100) NULL,
  allocated_by VARCHAR(50) NULL,
  allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_folio_allocation_operation(operation_id),
  UNIQUE KEY uq_growth_folio_tx(folio_id,transaction_id),
  INDEX idx_growth_folio_allocation_tx(transaction_id),
  INDEX idx_growth_folio_allocation_folio(folio_id,allocated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_folio_charge_allocations (
  id VARCHAR(80) PRIMARY KEY,
  folio_id VARCHAR(80) NOT NULL,
  booking_id VARCHAR(100) NOT NULL,
  charge_type VARCHAR(30) NOT NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  source_amount_snapshot DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  operation_id VARCHAR(100) NULL,
  allocated_by VARCHAR(50) NULL,
  allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_folio_charge(folio_id,booking_id,charge_type),
  UNIQUE KEY uq_growth_folio_charge_operation(operation_id),
  INDEX idx_growth_folio_charge_booking(booking_id,charge_type),
  INDEX idx_growth_folio_charge_folio(folio_id,allocated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_folio_routing_rules (
  id VARCHAR(80) PRIMARY KEY,
  booking_id VARCHAR(100) NULL,
  group_id VARCHAR(80) NULL,
  target_folio_id VARCHAR(80) NOT NULL,
  transaction_kind_pattern VARCHAR(80) NOT NULL DEFAULT '*',
  category_pattern VARCHAR(120) NOT NULL DEFAULT '*',
  route_percent DECIMAL(7,4) NOT NULL DEFAULT 100,
  priority INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  operation_id VARCHAR(100) NULL,
  created_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_folio_route_operation(operation_id),
  INDEX idx_growth_folio_route_booking(booking_id,active,priority),
  INDEX idx_growth_folio_route_group(group_id,active,priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_folio_invoices (
  id VARCHAR(80) PRIMARY KEY,
  invoice_number VARCHAR(80) NOT NULL,
  folio_id VARCHAR(80) NOT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'issued',
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
  source_hash CHAR(64) NOT NULL,
  snapshot_json LONGTEXT NOT NULL,
  notes VARCHAR(500) NULL,
  operation_id VARCHAR(100) NULL,
  created_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_by VARCHAR(50) NULL,
  voided_at DATETIME NULL,
  void_reason VARCHAR(500) NULL,
  UNIQUE KEY uq_growth_folio_invoice_number(invoice_number),
  UNIQUE KEY uq_growth_folio_invoice_operation(operation_id),
  INDEX idx_growth_folio_invoice_folio(folio_id,status,issue_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_purchase_requests (
  id VARCHAR(80) PRIMARY KEY,
  pr_number VARCHAR(80) NOT NULL,
  request_date DATE NOT NULL,
  department VARCHAR(100) NOT NULL DEFAULT 'General',
  requested_by VARCHAR(50) NULL,
  needed_by DATE NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  justification TEXT NULL,
  operation_id VARCHAR(100) NULL,
  approved_by VARCHAR(50) NULL,
  approved_at DATETIME NULL,
  rejected_by VARCHAR(50) NULL,
  rejected_at DATETIME NULL,
  rejection_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_growth_pr_number(pr_number),
  UNIQUE KEY uq_growth_pr_operation(operation_id),
  INDEX idx_growth_pr_status(status,request_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_purchase_request_items (
  id VARCHAR(80) PRIMARY KEY,
  pr_id VARCHAR(80) NOT NULL,
  inventory_id VARCHAR(100) NULL,
  pos_product_id VARCHAR(100) NULL,
  sku VARCHAR(100) NULL,
  item_name VARCHAR(190) NOT NULL,
  quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
  unit VARCHAR(40) NOT NULL DEFAULT 'pcs',
  estimated_unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  INDEX idx_growth_pr_item_pr(pr_id),
  INDEX idx_growth_pr_item_inventory(inventory_id),
  INDEX idx_growth_pr_item_pos(pos_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_goods_receipts (
  id VARCHAR(80) PRIMARY KEY,
  grn_number VARCHAR(80) NOT NULL,
  po_id VARCHAR(80) NOT NULL,
  receipt_date DATE NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  location VARCHAR(120) NULL,
  notes TEXT NULL,
  operation_id VARCHAR(100) NULL,
  received_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_by VARCHAR(50) NULL,
  posted_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_growth_grn_number(grn_number),
  UNIQUE KEY uq_growth_grn_operation(operation_id),
  INDEX idx_growth_grn_po(po_id,status,receipt_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_goods_receipt_items (
  id VARCHAR(80) PRIMARY KEY,
  grn_id VARCHAR(80) NOT NULL,
  po_item_id VARCHAR(80) NOT NULL,
  inventory_id VARCHAR(100) NULL,
  pos_product_id VARCHAR(100) NULL,
  quantity_received DECIMAL(15,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
  stock_applied TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  UNIQUE KEY uq_growth_grn_po_item(grn_id,po_item_id),
  INDEX idx_growth_grn_item_inventory(inventory_id),
  INDEX idx_growth_grn_item_pos(pos_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_supplier_invoices (
  id VARCHAR(80) PRIMARY KEY,
  supplier_invoice_number VARCHAR(100) NOT NULL,
  vendor_id VARCHAR(80) NOT NULL,
  po_id VARCHAR(80) NULL,
  grn_id VARCHAR(80) NULL,
  invoice_date DATE NOT NULL,
  due_date DATE NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
  notes TEXT NULL,
  operation_id VARCHAR(100) NULL,
  posted_by VARCHAR(50) NULL,
  posted_at DATETIME NULL,
  created_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_growth_supplier_invoice_vendor(vendor_id,supplier_invoice_number),
  UNIQUE KEY uq_growth_supplier_invoice_operation(operation_id),
  INDEX idx_growth_supplier_invoice_due(vendor_id,status,due_date),
  INDEX idx_growth_supplier_invoice_po(po_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_supplier_invoice_lines (
  id VARCHAR(80) PRIMARY KEY,
  supplier_invoice_id VARCHAR(80) NOT NULL,
  po_item_id VARCHAR(80) NULL,
  item_name VARCHAR(190) NOT NULL,
  account_class VARCHAR(30) NOT NULL DEFAULT 'expense',
  expense_category VARCHAR(120) NOT NULL DEFAULT 'Pembelian / Supplier',
  quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
  unit VARCHAR(40) NOT NULL DEFAULT 'pcs',
  unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(9,4) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  canonical_transaction_id VARCHAR(100) NULL,
  notes VARCHAR(500) NULL,
  INDEX idx_growth_supplier_invoice_line_invoice(supplier_invoice_id),
  INDEX idx_growth_supplier_invoice_line_tx(canonical_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_supplier_invoice_payments (
  id VARCHAR(80) PRIMARY KEY,
  supplier_invoice_id VARCHAR(80) NOT NULL,
  transaction_id VARCHAR(100) NOT NULL,
  amount_applied DECIMAL(15,2) NOT NULL DEFAULT 0,
  operation_id VARCHAR(100) NULL,
  linked_by VARCHAR(50) NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_supplier_invoice_payment_tx(supplier_invoice_id,transaction_id),
  UNIQUE KEY uq_growth_supplier_invoice_payment_operation(operation_id),
  INDEX idx_growth_supplier_invoice_payment_invoice(supplier_invoice_id),
  INDEX idx_growth_supplier_invoice_payment_tx(transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_guest_booking_links (
  id VARCHAR(80) PRIMARY KEY,
  guest_profile_id VARCHAR(80) NOT NULL,
  booking_id VARCHAR(100) NOT NULL,
  link_method VARCHAR(30) NOT NULL DEFAULT 'manual',
  confidence_score DECIMAL(7,4) NOT NULL DEFAULT 1,
  operation_id VARCHAR(100) NULL,
  linked_by VARCHAR(50) NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_guest_booking(booking_id),
  UNIQUE KEY uq_growth_guest_booking_operation(operation_id),
  INDEX idx_growth_guest_booking_guest(guest_profile_id,linked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_loyalty_accounts (
  id VARCHAR(80) PRIMARY KEY,
  guest_profile_id VARCHAR(80) NOT NULL,
  member_number VARCHAR(80) NOT NULL,
  tier VARCHAR(30) NOT NULL DEFAULT 'member',
  points_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
  lifetime_points DECIMAL(15,2) NOT NULL DEFAULT 0,
  lifetime_spend DECIMAL(18,2) NOT NULL DEFAULT 0,
  lifetime_nights INT NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_loyalty_guest(guest_profile_id),
  UNIQUE KEY uq_growth_loyalty_member(member_number),
  INDEX idx_growth_loyalty_tier(tier,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_loyalty_ledger (
  id VARCHAR(80) PRIMARY KEY,
  loyalty_account_id VARCHAR(80) NOT NULL,
  entry_type VARCHAR(30) NOT NULL,
  points DECIMAL(15,2) NOT NULL DEFAULT 0,
  spend_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  stay_nights INT NOT NULL DEFAULT 0,
  booking_id VARCHAR(100) NULL,
  reason VARCHAR(500) NOT NULL,
  operation_id VARCHAR(100) NULL,
  created_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_loyalty_operation(operation_id),
  UNIQUE KEY uq_growth_loyalty_booking_entry(booking_id,entry_type),
  INDEX idx_growth_loyalty_ledger_account(loyalty_account_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_guest_consents (
  id VARCHAR(80) PRIMARY KEY,
  guest_profile_id VARCHAR(80) NOT NULL,
  consent_type VARCHAR(50) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'granted',
  source VARCHAR(50) NOT NULL DEFAULT 'staff_confirmed',
  evidence_reference VARCHAR(190) NULL,
  captured_by VARCHAR(50) NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  expires_at DATETIME NULL,
  operation_id VARCHAR(100) NULL,
  UNIQUE KEY uq_growth_guest_consent_operation(operation_id),
  INDEX idx_growth_guest_consent_guest(guest_profile_id,consent_type,status,captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_crm_segments (
  id VARCHAR(80) PRIMARY KEY,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  condition_json LONGTEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_crm_segment_code(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_crm_campaigns (
  id VARCHAR(80) PRIMARY KEY,
  campaign_code VARCHAR(80) NOT NULL,
  name VARCHAR(180) NOT NULL,
  segment_id VARCHAR(80) NULL,
  channel VARCHAR(30) NOT NULL DEFAULT 'email',
  subject VARCHAR(190) NULL,
  message_template TEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  approved_by VARCHAR(50) NULL,
  approved_at DATETIME NULL,
  operation_id VARCHAR(100) NULL,
  created_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_crm_campaign_code(campaign_code),
  UNIQUE KEY uq_growth_crm_campaign_operation(operation_id),
  INDEX idx_growth_crm_campaign_status(status,scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_crm_campaign_recipients (
  id VARCHAR(80) PRIMARY KEY,
  campaign_id VARCHAR(80) NOT NULL,
  guest_profile_id VARCHAR(80) NOT NULL,
  consent_id VARCHAR(80) NULL,
  destination_hash CHAR(64) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  last_error VARCHAR(500) NULL,
  queued_at DATETIME NULL,
  sent_at DATETIME NULL,
  UNIQUE KEY uq_growth_campaign_guest(campaign_id,guest_profile_id),
  INDEX idx_growth_campaign_recipient_status(campaign_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_health_alert_rules (
  id VARCHAR(80) PRIMARY KEY,
  code VARCHAR(80) NOT NULL,
  metric_key VARCHAR(100) NOT NULL,
  comparison VARCHAR(10) NOT NULL DEFAULT 'gt',
  threshold_value DECIMAL(18,4) NOT NULL DEFAULT 0,
  severity VARCHAR(20) NOT NULL DEFAULT 'warning',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_health_rule_code(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_provider_adapters (
  id VARCHAR(80) PRIMARY KEY,
  adapter_type VARCHAR(30) NOT NULL,
  provider_code VARCHAR(50) NOT NULL,
  mode VARCHAR(30) NOT NULL DEFAULT 'disabled',
  config_json TEXT NULL,
  credential_reference VARCHAR(190) NULL,
  webhook_verifier VARCHAR(80) NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_growth_provider_adapter(adapter_type,provider_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_purchase_request_po_links (
  id VARCHAR(80) PRIMARY KEY,
  pr_id VARCHAR(80) NOT NULL,
  po_id VARCHAR(80) NOT NULL,
  operation_id VARCHAR(100) NULL,
  linked_by VARCHAR(50) NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_growth_pr_po(pr_id,po_id),
  UNIQUE KEY uq_growth_pr_po_operation(operation_id),
  INDEX idx_growth_pr_po_pr(pr_id),
  INDEX idx_growth_pr_po_po(po_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_loyalty_vouchers (
  id VARCHAR(80) PRIMARY KEY,
  voucher_code VARCHAR(80) NOT NULL,
  guest_profile_id VARCHAR(80) NOT NULL,
  value_type VARCHAR(30) NOT NULL DEFAULT 'amount',
  value_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  min_spend DECIMAL(15,2) NOT NULL DEFAULT 0,
  valid_from DATE NOT NULL,
  valid_until DATE NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'issued',
  redeemed_booking_id VARCHAR(100) NULL,
  redemption_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  redemption_reference VARCHAR(190) NULL,
  issued_by VARCHAR(50) NULL,
  redeemed_by VARCHAR(50) NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  redeemed_at DATETIME NULL,
  notes VARCHAR(500) NULL,
  operation_id VARCHAR(100) NULL,
  UNIQUE KEY uq_growth_loyalty_voucher_code(voucher_code),
  UNIQUE KEY uq_growth_loyalty_voucher_operation(operation_id),
  INDEX idx_growth_loyalty_voucher_guest(guest_profile_id,status,valid_until),
  INDEX idx_growth_loyalty_voucher_booking(redeemed_booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jaminan ganda: samakan collation semua tabel modul ini dengan core tables.
ALTER TABLE `growth_folios` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_folio_transaction_allocations` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_folio_charge_allocations` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_folio_routing_rules` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_folio_invoices` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_purchase_requests` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_purchase_request_items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_goods_receipts` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_goods_receipt_items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_supplier_invoices` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_supplier_invoice_lines` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_supplier_invoice_payments` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_guest_booking_links` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_loyalty_accounts` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_loyalty_ledger` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_guest_consents` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_crm_segments` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_crm_campaigns` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_crm_campaign_recipients` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_health_alert_rules` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_provider_adapters` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_purchase_request_po_links` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `growth_loyalty_vouchers` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS growth_internal_memos (
  id VARCHAR(80) PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  category VARCHAR(50) NOT NULL DEFAULT 'general',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_by VARCHAR(50) NOT NULL,
  updated_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at DATETIME NULL,
  INDEX idx_growth_internal_memo_status(status,updated_at),
  INDEX idx_growth_internal_memo_category(category,updated_at),
  INDEX idx_growth_internal_memo_creator(created_by,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `growth_internal_memos` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
