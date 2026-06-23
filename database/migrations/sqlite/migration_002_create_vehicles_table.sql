-- SQLite Migration: Create vehicles table

CREATE TABLE IF NOT EXISTS vehicles (
    id TEXT PRIMARY KEY,
    owner_id TEXT NOT NULL,
    make TEXT NOT NULL,
    model TEXT NOT NULL,
    year INTEGER NOT NULL,
    license_plate TEXT NOT NULL,
    price_per_day REAL NOT NULL DEFAULT 0.0,
    price_per_hour REAL NOT NULL DEFAULT 0.0,
    status TEXT NOT NULL DEFAULT 'pending',
    rejection_reason TEXT NULL,
    reviewed_by TEXT NULL,
    reviewed_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);
