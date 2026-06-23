-- MySQL Migration: Create vehicles table

CREATE TABLE IF NOT EXISTS vehicles (
    id CHAR(36) NOT NULL,
    owner_id CHAR(36) NOT NULL,
    make VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    year INT NOT NULL,
    license_plate VARCHAR(50) NOT NULL,
    price_per_day DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    price_per_hour DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(512) NULL,
    reviewed_by CHAR(36) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vehicles_owner (owner_id),
    CONSTRAINT fk_vehicles_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
