PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  pin_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN ('parent', 'child')),
  theme TEXT NOT NULL DEFAULT 'light' CHECK (theme IN ('light', 'dark')),
  accent_hue INTEGER NOT NULL DEFAULT 90 CHECK (accent_hue BETWEEN 0 AND 360),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ingredients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  category TEXT NOT NULL,
  unit TEXT NOT NULL,
  estimated_price REAL NOT NULL CHECK (estimated_price >= 0)
);

CREATE TABLE IF NOT EXISTS meals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  prep_steps TEXT NOT NULL, -- JSON array of strings, one per step
  image TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS meal_ingredients (
  meal_id INTEGER NOT NULL REFERENCES meals(id) ON DELETE CASCADE,
  ingredient_id INTEGER NOT NULL REFERENCES ingredients(id),
  qty REAL NOT NULL CHECK (qty > 0),
  PRIMARY KEY (meal_id, ingredient_id)
);

CREATE INDEX IF NOT EXISTS idx_meal_ingredients_ingredient ON meal_ingredients(ingredient_id);

-- One cycle = one shopping-to-shopping menu period (see docs/cycle rule in PLAN.md).
CREATE TABLE IF NOT EXISTS menu_cycles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  start_date TEXT NOT NULL,
  end_date TEXT NOT NULL,
  label TEXT NOT NULL,
  UNIQUE (start_date, end_date)
);

CREATE TABLE IF NOT EXISTS menu_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cycle_id INTEGER NOT NULL REFERENCES menu_cycles(id) ON DELETE CASCADE,
  date TEXT NOT NULL,
  slot TEXT NOT NULL CHECK (slot IN ('breakfast', 'lunch', 'dinner')),
  meal_id INTEGER NOT NULL REFERENCES meals(id),
  cooked INTEGER NOT NULL DEFAULT 0 CHECK (cooked IN (0, 1)),
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_menu_entries_cycle_date ON menu_entries(cycle_id, date);

-- Single row (id is always 1) holding the family's shared grocery budget.
CREATE TABLE IF NOT EXISTS budget_settings (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  monthly_budget REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS favorites (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  meal_id INTEGER NOT NULL REFERENCES meals(id) ON DELETE CASCADE,
  PRIMARY KEY (user_id, meal_id)
);

-- Ticked-off state of items on a cycle's shopping list; presence = checked.
CREATE TABLE IF NOT EXISTS shopping_checks (
  cycle_id INTEGER NOT NULL REFERENCES menu_cycles(id) ON DELETE CASCADE,
  ingredient_id INTEGER NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
  PRIMARY KEY (cycle_id, ingredient_id)
);

CREATE TABLE IF NOT EXISTS feedback (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  meal_name TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'added', 'dismissed')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
