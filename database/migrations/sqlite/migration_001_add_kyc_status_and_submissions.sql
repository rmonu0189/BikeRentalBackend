-- SQLite Migration: Add KYC Status column to users and create kyc_submissions table

ALTER TABLE users ADD COLUMN kyc_status TEXT NOT NULL DEFAULT 'unverified';

CREATE TABLE IF NOT EXISTS kyc_submissions (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    address_proof_path TEXT NOT NULL,
    identity_proof_path TEXT NOT NULL,
    address_details TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    rejection_reason TEXT NULL,
    reviewed_by TEXT NULL,
    reviewed_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
