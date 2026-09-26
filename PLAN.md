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
- `shopping_checks` — cycle_id, ingredient_id (presence = ticked off on that
  cycle's shopping list)
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

### Phase 4 — Auth (PIN + Email, Guest) + Family Member Management
Wire the Phase 2 login screen to the database: email + PIN check against
`users`, session handling, role (parent/child) stored in session. Guest button
starts a read-only session with no DB lookup. Since managing who's in
`users` is directly tied to auth, pulled forward from Phase 11: Settings
gained a parent-only Family Members list (name/email/role, remove with a
confirm) and an "+ Add Family Member" modal (name/email/PIN/role), with
guards against removing yourself or the last remaining parent.
**Deliverable:** log in as a seeded parent or child, or continue as guest;
session persists across pages; a parent can add/remove family members from
Settings.

### Phase 5 — Today View, Meal Detail & Ingredient Catalog
Replace hardcoded data on the Today and Meal Detail pages with real queries
(today's cycle/date lookup, per-slot menu entries, real favorite/cooked
toggles enforced server-side — guest requests are no-ops even if crafted
directly). A second oversight surfaced during review: there was no page to
manage the priced ingredient catalog required by the ingredients decision
in Phase 3. Added `ingredients.php` (parent-only: list by category, "+ Add
Ingredient" modal for name/category/unit/price, duplicate-name guard), with
entry points from Settings and from the recipe header.
**Deliverable:** today's real menu renders (including an honest empty state
when no cycle covers the current date), tapping a meal shows its real
recipe with a real cost total, cooked/favorite toggles persist, and a
parent can grow the ingredient catalog from either Settings or a recipe.

### Phase 6 — Calendar & Week Views (dynamic, read-only)
Wire the full-cycle grid and week view to real `menu_cycles`/`menu_entries`
data. Cycle resolution defaults to whichever cycle covers today (falling
back to the soonest upcoming, then most recent past); `?cycle=` / `?week=`
navigate explicitly, with week navigation crossing cycle boundaries
seamlessly when adjacent cycles exist (prev/next disable gracefully at the
edges when they don't, e.g. with only one seeded cycle). Cooked toggling
and tap-through to the recipe work in both views, same as Today — only
adding/reordering meals is out of scope here (Phase 7).
**Deliverable:** full cycle and week are browsable with real DB-backed
content; caught and fixed a real bug along the way where full-length meal
titles overflowed the calendar grid (CSS Grid's `min-width: auto` letting
`nowrap` text force columns wider than the viewport — the Phase 2 mockup's
hand-picked short labels had hidden this).

### Phase 7 — Parent Menu Editing
`meals.php` (library, parent-only) + `meal-form.php` (shared add/edit: title,
description, ingredients picked from the Phase 5 catalog via dynamic rows,
one-step-per-line prep steps, optional photo upload to `img/meals/`).
Delete is blocked with a clear message if the meal is still scheduled
anywhere. On `week.php`, empty slots get a parent-only "+ Add" that opens a
meal picker; filled slots get a remove (✕); real HTML5 drag-and-drop moves
or swaps meals between date+slot, blocked server-side whenever either the
dragged or the target entry is `cooked`. A live budget banner (reused on
`calendar.php` too) sums `qty × estimated_price` across every entry in the
cycle — including repeats of the same meal — against
`budget_settings.monthly_budget` and warns when over, updating immediately
after every edit rather than waiting for a single "save".
**Deliverable:** a Parent can build a real menu end to end (add meals,
schedule them, rearrange by dragging) and sees a live warning when a
cycle goes over budget. Verified with real ingredient math, a real
browser drag gesture, and the cooked-meal lock in both directions.

Also fixed a real, previously-undetected bug from Phase 5: `mockup.js`'s
global `.fav-btn` click handler called `preventDefault()` on every such
button, including the real `type="submit"` ones added in `today.php` /
`meal-detail.php` — so a genuine browser click never actually reached the
server (curl-based verification during Phase 5 had bypassed this entirely
by posting directly). Now it only intercepts the still-static instances
that aren't wired to a form yet.

### Phase 8 — Favorites & Search
Favorite toggling itself already existed (Phase 5) but had nowhere to
browse from outside a scheduled day. Added `search.php` — by title or
ingredient (empty query browses the whole library), reachable via a new
search icon on Today — and extended `meal-detail.php` to open a meal
library-wide (`?meal=ID`, no date/slot/cooked context) alongside its
existing per-occurrence view (`?entry=ID`). Settings' favorites section,
static since Phase 2, is now a real per-user list with working remove.
**Deliverable:** search returns matching meals by name or ingredient,
guests can browse read-only, and the favorites list in Settings reflects
and edits real data. Verified with a real Playwright search-then-favorite
click-through, not just curl.

### Phase 9 — Shopping List
`shopping-list.php` replaces the static mockup: resolves a cycle the same
way Calendar/Week do (`?cycle=` or whichever covers today, falling back to
soonest-upcoming/most-recent-past), with the same prev/next cycle nav.
Ingredients across every `menu_entries` row in the cycle are summed per
ingredient (`SUM(qty)`, correctly counting repeated meal occurrences) and
grouped under the same fixed category order used in `ingredients.php`
(produce, dairy, meat, bakery, pantry, spices, frozen), each line showing
quantity/unit and estimated cost, with the same budget banner as
Calendar/Week. A new `shopping_checks` table (`cycle_id`, `ingredient_id`)
persists which items are ticked off — real per-item checkboxes toggle via
the usual POST/redirect pattern, so a check made on one family member's
phone shows up for everyone. Guests get the same list read-only (disabled
checkboxes, no form). Share button uses the real Web Share API where
available, falling back to copy-to-clipboard.
**Deliverable:** shopping list generates correctly, with accurate costs,
from a real cycle; checking items off persists per-cycle; verified the
aggregation math against a raw DB query and a real Playwright click on the
checklist (not just curl) round-tripped a check to the database and back.

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
