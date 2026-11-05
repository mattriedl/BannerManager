-- SQLite equivalent of schema.sql, for local testing only.
-- Keep in sync with the schema inside install.php.

PRAGMA foreign_keys = ON;

CREATE TABLE gb_spots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  width INTEGER NOT NULL DEFAULT 300,
  height INTEGER NOT NULL DEFAULT 250,
  price NUMERIC NOT NULL DEFAULT 10.00,
  period_days INTEGER NOT NULL DEFAULT 30,
  is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE gb_banners (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  spot_id INTEGER NOT NULL REFERENCES gb_spots(id) ON DELETE CASCADE,
  advertiser_name TEXT NULL,
  advertiser_email TEXT NULL,
  kind TEXT NOT NULL DEFAULT 'image' CHECK (kind IN ('image','html')),
  image_path TEXT NULL,
  html TEXT NULL,
  target_url TEXT NULL,
  width INTEGER NOT NULL DEFAULT 300,
  height INTEGER NOT NULL DEFAULT 250,
  status TEXT NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','active','paused','rejected','expired')),
  paid INTEGER NOT NULL DEFAULT 0,
  starts_at TEXT NULL,
  ends_at TEXT NULL,
  impressions INTEGER NOT NULL DEFAULT 0,
  clicks INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_serve ON gb_banners (spot_id, status, ends_at);

CREATE TABLE gb_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  banner_id INTEGER NOT NULL REFERENCES gb_banners(id) ON DELETE CASCADE,
  gateway TEXT NOT NULL CHECK (gateway IN ('paypal','stripe','manual')),
  amount NUMERIC NOT NULL,
  currency TEXT NOT NULL DEFAULT 'USD',
  status TEXT NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','paid','failed','refunded')),
  txn_ref TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE gb_stats (
  banner_id INTEGER NOT NULL REFERENCES gb_banners(id) ON DELETE CASCADE,
  day TEXT NOT NULL,
  impressions INTEGER NOT NULL DEFAULT 0,
  clicks INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (banner_id, day)
);

INSERT INTO gb_spots (slug,name,width,height,price,period_days) VALUES
('medium-rectangle','Medium Rectangle',300,250,10.00,30),
('leaderboard','Leaderboard',728,90,10.00,30),
('full-banner','Full Banner',468,60,10.00,30),
('mobile-banner','Mobile Banner',320,50,10.00,30),
('skyscraper','Skyscraper',160,600,10.00,30),
('half-page','Half Page',300,600,10.00,30),
('large-rectangle','Large Rectangle',336,280,10.00,30),
('square','Square',250,250,10.00,30);
