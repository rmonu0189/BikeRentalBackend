-- MySQL Migration: Add KYC Status column to users and create kyc_submissions table

ALTER TABLE users ADD COLUMN kyc_status VARCHAR(20) NOT NULL DEFAULT 'unverified';

CREATE TABLE IF NOT EXISTS kyc_submissions (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    address_proof_path VARCHAR(512) NOT NULL,
    identity_proof_path VARCHAR(512) NOT NULL,
    address_details TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(512) NULL,
    reviewed_by CHAR(36) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_kyc_user (user_id),
    CONSTRAINT fk_kyc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
