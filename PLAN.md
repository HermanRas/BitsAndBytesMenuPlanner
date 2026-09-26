# BitsAndBytesMenuPlanner — Family Meal Planner

Display name: **BitsAndBytesMenuPlanner**, abbreviated to **B&B** wherever
space is tight (nav bar, PWA home-screen label, small buttons).

A mobile-first PWA for planning the family's monthly menu, viewing recipes, tracking
what's been cooked, and generating shopping lists. Self-contained PHP + SQLite
container, styled after the `HappyHubby` project's simple container setup (no
framework — plain PHP served via the built-in dev server, Composer only if a
dependency becomes necessary).

Sample files in `Docs/` (`Menu.jpg`, `Sample_MealLayout.md`,
`Sample_shoppinglist.md`) are GPT-generated exports of a real family menu —
useful as **seed/placeholder content** for the mockup and dev database, but not
guaranteed accurate. Treat them as flavor, not spec.

---

## Product Requirements Summary

- **Landing page**: today's menu, one card per meal slot (breakfast/lunch/dinner).
  Tapping a meal opens its recipe: description, ingredients, prep steps, image.
- **Calendar view**: full month grid (each day shows its meal titles per slot),
  plus a week-at-a-time view with prev/next navigation.
- **Roles**: whole family shares one menu. Only **Parents** can add/edit meals,
  edit the menu, or reorder meals. Children/guests are read-only + can favorite
  and mark meals cooked.
- **Cooked tracking**: meals default to "not cooked"; can be toggled to
  "cooked". Parents can drag-and-drop reorder meals that are still "not cooked".
- **Shopping list**: auto-generated from selected meals/date range, grouped by
  category (produce, dairy, meat, etc.), shareable with family.
- **Meals**: title, description, ingredients, prep steps, image. Searchable by
  name or ingredient. Ingredients are picked from a **priced ingredient
  catalog** — a new ingredient must have a category, unit, and estimated price
  entered once before it can be used in any meal.
- **Budget**: Parents set a global monthly budget in Settings. Saving/editing
  a menu cycle computes the cycle's estimated total cost (sum of
  ingredient prices across its meals) and warns if it exceeds the budget.
- **Favorites**: any user can mark meals as favorite.
- **Copy cycle**: Parents can duplicate a past cycle's menu (meals copied,
  cooked-status reset) into a new cycle.
- **Auth**: PIN + email login for family members; a **Guest** button gives
  read-only access to the menu and recipes with no login.
- **Settings**: color theme (light/dark + a per-user accent hue), PIN code,
  monthly budget, and favorite meals management.
- **Feedback**: simple form for suggesting new meals.
- **No notifications** of any kind.
- **PWA**: installable on mobile home screen; **not** required to work offline.
- **Design**: mobile-first, clean, bold buttons, minimal chrome, high glanceability.

### Data model (informing the schema in Phase 3)

- `users` — name, email, pin_hash, role (parent/child), theme, accent_hue
- `ingredients` — catalog: name, category (produce/dairy/meat/etc.), unit,
  estimated_price. A meal can only reference ingredients that already exist
  here — adding a brand-new ingredient means entering its price first.
- `meals` — title, description, prep_steps, image
- `meal_ingredients` — meal_id, ingredient_id, qty (join table; enables both
  shopping-list aggregation and per-meal/per-cycle cost estimates)
- `menu_cycles` — start_date, end_date, label (see cycle rule below). A menu
  is always edited/copied one cycle at a time.
- `menu_entries` — cycle_id, date, slot (breakfast/lunch/dinner), meal_id,
  cooked (bool), sort_order
- `budget_settings` — monthly_budget (single row, Parent-editable)
- `favorites` — user_id, meal_id
- `feedback` — user_id, suggested meal text, status

### Decisions log

**1. Menu cycle rule.** Salary is paid on the 25th. The family shops the
first weekend after the 25th, and the menu starts the Monday after that
shopping trip. The cycle then runs until the weekend that covers the 25th of
the *following* month (which is also the shopping weekend that kicks off the
next cycle — cycles are back-to-back, no gap).

```
function shopping_weekend(payday):        # payday = the 25th of some month
    days_to_sat = (5 - payday.weekday()) % 7   # Mon=0 ... Sat=5
    sat = payday + days_to_sat days
    return sat, sat + 1 day                     # (Saturday, Sunday)

function generate_cycle(month, year):
    _, this_sun  = shopping_weekend(date(year, month, 25))
    start = this_sun + 1 day                    # Monday after this month's shop

    next_month, next_year = month+1, year        # roll over at December
    _, next_sun = shopping_weekend(date(next_year, next_month, 25))
    end = next_sun                                # Sunday covering next month's 25th

    return start, end
```

Validated against the sample `Menu.jpg`: for a month where the 25th falls on
a Friday, this produces `start = Monday 28 Sep`, `end = Sunday 01 Nov` —
exactly what the image shows. (The companion `Sample_MealLayout.md` mislabels
that last day "Sunday — 25 October"; the image is correct and this is a good
example of why the sample `.md` files are flavor, not ground truth.)

Cycles are stored as rows (`menu_cycles`) generated by this function rather
than computed ad hoc, so Copy Cycle and calendar navigation just read
existing rows.

**2. App name.** `BitsAndBytesMenuPlanner`, short form `B&B`.

**3. Color theme.** Base palette pulled from the supplied app icon (black
rounded-square badge, white torn-receipt "B", lime-green checklist accent):

| Token | Light | Dark |
|---|---|---|
| Background | `#F4F5F2` | `#111214` |
| Surface / card | `#FFFFFF` | `#1C1E20` |
| Text | `#1A1A1A` | `#F2F2F2` |
| Muted / border | `#D8D8D8` | `#3A3D40` |

Both light and dark are first-class (Settings lets a user pick). Revised
during the Phase 2 mockup review: rather than a fixed lime-green accent, the
accent is **hue-driven and per-user** — `--accent-h` (0–360, default 90 ≈ the
icon's lime green) is the one thing a user picks via a hue slider; saturation
and lightness are fixed per theme (`hsl(var(--accent-h) 45% 42%)` light,
`hsl(var(--accent-h) 55% 55%)` dark) so every accent token derives from that
one value. Stored as `users.accent_hue`. The static app icon itself is not
user-customizable, so there's no icon picker in Settings.

**4. Ingredients.** Structured, via a shared `ingredients` catalog (name,
category, unit, estimated_price) plus a `meal_ingredients` join table —
required for both shopping-list category grouping and cost/budget
estimation. Enforced at meal-creation time: the meal-ingredient picker only
offers catalog entries; adding a new one requires a price up front.

---

## Build Phases

Each phase is independently runnable/demoable before moving to the next.

### Phase 1 — Project Skeleton & Container
Set up `docker-compose.yml` + PHP dockerfile (mirroring HappyHubby: PHP CLI
server, no framework), folder structure (`src/`, `src/css`, `src/img`,
`src/data` for the SQLite file), `.gitignore`, git init. `index.php` serves a
placeholder "It works" page.
**Deliverable:** `docker compose up` serves a page at `localhost:8080`.

### Phase 2 — HTML-Only Mockup (look & feel sign-off)
Static HTML/CSS pages, **hardcoded sample data** (from the sample files),
**no PHP logic, no database**. Pages: Today, Meal Detail, Month Calendar, Week
View, Shopping List, Settings, Login/Guest screen. Linked together as a
clickable prototype so navigation and interactions (tabs, favorite toggle,
cooked toggle, drag-and-drop feel) can be reviewed. Establishes color theme,
typography, button style, and icon set.
**Deliverable:** browsable static prototype — review and sign off on look and
feel here, before any backend work starts.

### Phase 3 — Database Schema & Seed Data
Write `schema.sql` for the tables above (including `ingredients`,
`meal_ingredients`, `menu_cycles`, `budget_settings`), a PHP init script that
creates the SQLite file if missing, a small helper implementing the
`generate_cycle()` rule, and a seed script that loads sample meals/menu/prices
from the sample files as placeholder data.
**Deliverable:** `family.sqlite` created with schema + seed data (including a
generated cycle matching the sample menu dates), verified by a throwaway
script dumping table contents.

### Phase 4 — Auth (PIN + Email, Guest)
Wire the Phase 2 login screen to the database: email + PIN check against
`users`, session handling, role (parent/child) stored in session. Guest button
starts a read-only session with no DB lookup.
**Deliverable:** log in as a seeded parent or child, or continue as guest;
session persists across pages.

### Phase 5 — Today View & Meal Detail (dynamic, read-only)
Replace hardcoded data on the Today and Meal Detail pages with real queries.
Any logged-in user (not guest) can toggle a meal cooked/not cooked.
**Deliverable:** today's real menu renders, tapping a meal shows its real
recipe, cooked toggle persists.

### Phase 6 — Calendar & Week Views (dynamic, read-only)
Wire the month grid and week view to real `menu_cycles`/`menu_entries` data
with prev/next navigation between cycles.
**Deliverable:** full cycle is browsable with real DB-backed content.

### Phase 7 — Ingredient Catalog & Parent Menu Editing
Ingredient catalog CRUD first (name, category, unit, price) — this is the
prerequisite for everything else in this phase. Then: add/edit/delete a meal
(title, description, ingredients picked from the catalog only, steps, image
upload), assign meals to a date + slot, guarded to Parent role only.
Drag-and-drop reorder for meals still marked "not cooked". Saving a cycle's
menu computes its total estimated cost from `meal_ingredients` and warns if
it exceeds `budget_settings.monthly_budget`.
**Deliverable:** a Parent can price ingredients, build a real menu, and gets
a warning when a cycle goes over budget.

### Phase 8 — Favorites & Search
Favorite toggle stored per user; search across the meal library by name or
ingredient.
**Deliverable:** search returns matching meals; favorites list works.

### Phase 9 — Shopping List
Select a cycle (or custom date range), aggregate ingredients grouped by
category with quantities and estimated cost per item/category/total,
checklist UI, share (e.g. copy-to-clipboard/native share sheet).
**Deliverable:** shopping list generates correctly, with accurate costs,
from a real cycle.

### Phase 10 — Copy Cycle
Parent picks a past cycle to duplicate into a newly generated one (via
`generate_cycle()`); meals copy over, cooked-status resets to "not cooked".
**Deliverable:** copy produces a correct new, uncooked menu on the right
dates.

### Phase 11 — Settings
Light/dark toggle plus accent-hue slider (writes `users.theme` /
`accent_hue`), PIN change, monthly budget, favorites management — all
persisted and applied app-wide, per user.
**Deliverable:** theme, hue, PIN, and budget changes persist and take effect
immediately.

### Phase 12 — Feedback
Form to suggest a new meal, stored in `feedback`; Parents can review pending
suggestions and approve (adds to the meal catalog) or dismiss them.
**Deliverable:** suggestions round-trip from submission to Parent review.

### Phase 13 — PWA Packaging
`manifest.json`, install-only `service-worker.js` (no offline caching per
spec), icon set, install prompt.
**Deliverable:** app installs to a mobile home screen and launches standalone.

### Phase 14 — Polish & Hardening
Responsive QA across breakpoints, accessibility pass (contrast, tap target
size), PIN hashing/security review, input sanitization, empty/error states.
**Deliverable:** v1 ready for real family use.

---

## Container Reference

Following `HappyHubby`'s pattern:
- `php-server.dockerfile`: `php:8.3-cli-alpine`, `sqlite` package, runs
  `php -S 0.0.0.0:8000 -t /app`.
- `docker-compose.yml`: single `php-server` service, `./src:/app` volume,
  port mapped to host (e.g. `8080:8000`).
- No frameworks unless a specific need arises (HappyHubby only pulled in
  Composer packages for web-push notifications, which this app doesn't need).
