CREATE DATABASE IF NOT EXISTS db_warehouse
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_warehouse;

CREATE TABLE IF NOT EXISTS warehouses (
  id CHAR(36) NOT NULL PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS warehouse_stocks (
  warehouse_id CHAR(36) NOT NULL,
  product_id VARCHAR(128) NOT NULL,
  quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
  reserved BIGINT UNSIGNED NOT NULL DEFAULT 0,
  min_stock BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (warehouse_id, product_id),
  CONSTRAINT fk_stock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
  CONSTRAINT chk_stock_reserved CHECK (reserved <= quantity)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
  id CHAR(36) NOT NULL PRIMARY KEY,
  warehouse_id CHAR(36) NOT NULL,
  product_id VARCHAR(128) NOT NULL,
  movement_type ENUM('IN', 'OUT', 'RESERVE', 'RELEASE') NOT NULL,
  quantity BIGINT UNSIGNED NOT NULL,
  reference_type VARCHAR(40) NOT NULL,
  reference_id VARCHAR(128) NOT NULL,
  quantity_before BIGINT UNSIGNED NOT NULL,
  quantity_after BIGINT UNSIGNED NOT NULL,
  reserved_before BIGINT UNSIGNED NOT NULL,
  reserved_after BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_movement_product (warehouse_id, product_id, created_at),
  INDEX idx_movement_reference (reference_type, reference_id),
  CONSTRAINT fk_movement_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS warehouse_restock_orders (
  id VARCHAR(128) NOT NULL PRIMARY KEY,
  warehouse_id CHAR(36) NOT NULL,
  retail_id VARCHAR(128) NOT NULL,
  status ENUM('WAITING_SUPPLIER', 'RESERVED', 'SHIPPED', 'REJECTED') NOT NULL,
  rejection_reason VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_restock_status (status, created_at),
  CONSTRAINT fk_restock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS warehouse_restock_order_items (
  restock_order_id VARCHAR(128) NOT NULL,
  product_id VARCHAR(128) NOT NULL,
  quantity BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (restock_order_id, product_id),
  CONSTRAINT fk_restock_item_order FOREIGN KEY (restock_order_id)
    REFERENCES warehouse_restock_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_requests (
  id CHAR(36) NOT NULL PRIMARY KEY,
  po_id VARCHAR(128) NULL UNIQUE,
  supplier_id VARCHAR(128) NOT NULL,
  status ENUM('REQUESTED', 'CONFIRMED', 'SHIPPED', 'DELIVERED', 'PARTIALLY_RECEIVED', 'RECEIVED')
    NOT NULL DEFAULT 'REQUESTED',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_purchase_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_request_items (
  purchase_request_id CHAR(36) NOT NULL,
  warehouse_id CHAR(36) NOT NULL,
  product_id VARCHAR(128) NOT NULL,
  quantity BIGINT UNSIGNED NOT NULL,
  received_quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (purchase_request_id, warehouse_id, product_id),
  CONSTRAINT chk_received_le_ordered CHECK (received_quantity <= quantity),
  CONSTRAINT fk_purchase_item_request FOREIGN KEY (purchase_request_id)
    REFERENCES purchase_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_item_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS goods_receipts (
  id CHAR(36) NOT NULL PRIMARY KEY,
  purchase_request_id CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_goods_receipt_request FOREIGN KEY (purchase_request_id)
    REFERENCES purchase_requests(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS goods_receipt_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  goods_receipt_id CHAR(36) NOT NULL,
  warehouse_id CHAR(36) NOT NULL,
  product_id VARCHAR(128) NOT NULL,
  quantity BIGINT UNSIGNED NOT NULL,
  CONSTRAINT fk_goods_receipt_item_receipt FOREIGN KEY (goods_receipt_id)
    REFERENCES goods_receipts(id) ON DELETE CASCADE,
  CONSTRAINT fk_goods_receipt_item_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS processed_events (
  event_id VARCHAR(128) NOT NULL PRIMARY KEY,
  event_type VARCHAR(100) NOT NULL,
  processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS outbox_events (
  id CHAR(36) NOT NULL PRIMARY KEY,
  event_type VARCHAR(100) NOT NULL,
  payload JSON NOT NULL,
  status ENUM('PENDING', 'SENT') NOT NULL DEFAULT 'PENDING',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_until TIMESTAMP NULL,
  last_error VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL,
  INDEX idx_outbox_pending (status, available_at, locked_until)
) ENGINE=InnoDB;
