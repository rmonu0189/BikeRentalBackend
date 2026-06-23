-- BikeRental Database Schema for SQLite

CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    phone TEXT NOT NULL,
    country_code TEXT NOT NULL DEFAULT '+91',
    email TEXT NULL,
    full_name TEXT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    kyc_status TEXT NOT NULL DEFAULT 'unverified',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

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

CREATE UNIQUE INDEX IF NOT EXISTS uq_users_phone ON users(country_code, phone);



CREATE TABLE IF NOT EXISTS phone_otp_challenges (
    id TEXT PRIMARY KEY,
    phone TEXT NOT NULL,
    salt_hex TEXT NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    user_agent TEXT NULL,
    device_label TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Seed static test data
INSERT OR IGNORE INTO users (id, phone, country_code, email, full_name, role, is_active)
VALUES 
('user-1-uuid', '9109322140', '+91', 'user@local.test', 'John Doe', 'user', 1),
('admin-1-uuid', '9109322141', '+91', 'admin@local.test', 'Admin User', 'admin', 1);

