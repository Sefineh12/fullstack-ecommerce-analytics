PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL,
  email         TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'customer' CHECK (role IN ('customer', 'admin')),
  phone         TEXT NOT NULL DEFAULT '',
  address       TEXT NOT NULL DEFAULT '',
  photo         TEXT NOT NULL DEFAULT '',
  is_verified   INTEGER NOT NULL DEFAULT 0,
  banned        INTEGER NOT NULL DEFAULT 0,
  verify_hash   TEXT,
  reset_hash    TEXT,
  reset_expires TEXT,
  verify_sent_at TEXT,
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
  id   INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS products (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  name           TEXT NOT NULL,
  description    TEXT NOT NULL DEFAULT '',
  price_cents    INTEGER NOT NULL CHECK (price_cents >= 0),
  discount_cents INTEGER NOT NULL DEFAULT 0 CHECK (discount_cents >= 0),
  stock          INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
  brand          TEXT NOT NULL DEFAULT '',
  category_id    INTEGER REFERENCES categories(id),
  image_url      TEXT NOT NULL DEFAULT '',
  active         INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS product_images (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id INTEGER NOT NULL REFERENCES products(id),
  path       TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS orders (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id         INTEGER NOT NULL REFERENCES users(id),
  total_cents     INTEGER NOT NULL,
  status          TEXT NOT NULL DEFAULT 'Pending Verification',
  transaction_id  TEXT NOT NULL DEFAULT '',
  payment_receipt TEXT NOT NULL DEFAULT '',
  payment_status  TEXT NOT NULL DEFAULT 'Pending' CHECK (payment_status IN ('Pending', 'Approved', 'Rejected')),
  created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS order_items (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id         INTEGER NOT NULL REFERENCES orders(id),
  product_id       INTEGER NOT NULL REFERENCES products(id),
  quantity         INTEGER NOT NULL CHECK (quantity > 0),
  unit_price_cents INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
  email TEXT NOT NULL,
  at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notifications (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL REFERENCES users(id),
  message    TEXT NOT NULL,
  is_read    INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reviews (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id INTEGER NOT NULL REFERENCES products(id),
  user_id    INTEGER NOT NULL REFERENCES users(id),
  rating     INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment    TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (product_id, user_id)
);

CREATE TABLE IF NOT EXISTS wishlist (
  user_id    INTEGER NOT NULL REFERENCES users(id),
  product_id INTEGER NOT NULL REFERENCES products(id),
  PRIMARY KEY (user_id, product_id)
);