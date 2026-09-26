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
`copy-cycle.php` (parent-only, linked from Settings' new "Menu Cycle"
section). The destination is always the cycle immediately following
whichever cycle is currently newest in the DB — found via a new
`payday_month_for_end()` helper that locates the 25th within 7 days of a
cycle's `end_date` (cycles are contiguous, so this always identifies the
right next `generate_cycle()` call) — and is created fresh, never
overwriting an existing one. The Parent picks any past cycle as the
source; **mapping is by week index, wrapping with modulo**: destination
week 1 gets source week 1, week 2 gets week 2, and so on, and if the
destination runs longer than the source (e.g. copying a 4-week cycle into
a 5-week one) the extra week(s) repeat starting from source week 1 rather
than staying empty. All copied entries reset to `cooked = 0`.
**Deliverable:** copy produces a correct new, uncooked menu on the right
dates. Verified with a scripted fixture (4-week source, distinct meal per
week) copied into a real 5-week destination: weeks 1–4 landed on the
correct dates and week 5 correctly repeated week 1's meal, all reset to
not-cooked — plus a real Playwright click-through of the shrinking case
(5-week seeded cycle copied into a 4-week destination, only week 1 had
meals so only week 1 populated).

### Phase 11 — Settings
Light/dark toggle and accent-hue slider now write `users.theme` /
`accent_hue` (favorites management was already made real in Phase 8). A
new `theme_html_attrs($user)` helper renders `data-theme`/`--accent-h`
straight onto every page's `<html>` tag — the one deliberately shared
snippet threaded through all ~15 pages so a saved preference applies
app-wide immediately, not just on Settings — replacing the old
client-only/localStorage theme preview entirely (removed from
`mockup.js`, along with the now-dead static `.fav-btn` fallback it also
carried). The hue slider still live-previews while dragging via a tiny
inline script, then commits with a real POST on release (`change`, not
`input`); guests, who have no account row to persist to, no longer see
the Appearance/PIN/Budget sections at all rather than a preview that
silently goes nowhere. PIN change verifies the current PIN with
`password_verify()` before hashing and saving a new one; monthly budget
(Parent-only) updates `budget_settings`.
**Deliverable:** theme, hue, PIN, and budget changes persist and take
effect immediately. Verified with real Playwright interaction (not just
curl): clicking Dark and dragging the hue slider changed `<html>`'s
attributes immediately and still held after navigating to an unrelated
page (Today); curl round-tripped a PIN change end-to-end (old PIN
rejected, new one logs in) and confirmed guests/children/parents each see
the right subset of controls.

### Phase 12 — Feedback
`feedback.php` (any logged-in family member, not guest): submit a meal
name + optional note, and see everyone's suggestions with a status badge
(pending/added/dismissed) so people can avoid re-suggesting the same
thing. `feedback-review.php` (parent-only) lists pending suggestions
first, each with submitter name and a relative "N days/weeks ago"
timestamp; **Approve** inserts a bare stub meal (title only, no
ingredients/steps yet) and redirects straight into `meal-form.php?id=` so
the Parent can flesh it out immediately, marking the feedback row
`added`; **Dismiss** marks it `dismissed`. Both actions are guarded to
only affect rows still `pending`, so re-submitting an already-resolved
form (e.g. a double click) is a safe no-op rather than a duplicate meal.
Settings' Feedback section links are now gated server-side ($isParent /
$user !== null) instead of the old CSS-class + localStorage role trick.
**Deliverable:** suggestions round-trip from submission to Parent review.
Verified with a real Playwright click-through (submit → review → approve
→ landed on the new stub's edit form) plus curl checks for the
double-approve guard and guest/child access gating.

### Phase 13 — PWA Packaging
`manifest.json` (name/short_name "B&B", `start_url: /login.php`,
`standalone` display, portrait) plus a genuinely install-only
`service-worker.js` — `install`/`activate` only, deliberately no `fetch`
handler, so the app is never served from a cache and always hits the
network as normal. Generated real 192×192 and 512×512 icons from the
existing 1024×1024 source (resized via GD in a throwaway
`php:8.3.21-cli-alpine3.20` container — the running app container has no
image library installed, and per house rule nothing gets installed on the
dev box itself). The manifest link, apple-touch-icon, and a theme-color
meta tag were added to every page's `<head>` (the same one-line-per-file
pattern as Phase 11's `theme_html_attrs()`); the service worker registers
itself from `mockup.js` (already loaded everywhere), swallowing errors
silently since registration is expected to fail over plain HTTP on a
non-localhost host. Settings gained an "Install App" button, hidden by
default and only shown once a real `beforeinstallprompt` event fires.
**Deliverable:** app installs to a mobile home screen and launches
standalone. Verified for real, not just wiring: switched
`docker-compose.dev.yml`'s Playwright container from joining the app's
Docker network by service name to `network_mode: "container:bnb-planner"`
so it can reach the app via literal `http://localhost:8000` — a real
secure context — since a service-name origin like `php-server:8000` is
treated as insecure and silently disables `navigator.serviceWorker`
entirely. With that fixed, a real Playwright run confirmed the service
worker actually registers and reaches `activated`, the manifest parses
with the right name/icons, and the install button correctly stays hidden
until `beforeinstallprompt` fires, then prompts and hides again after a
simulated accept.

### Between phases — real click-through fixes
Two real bugs surfaced from an actual click-through on a phone (not just
curl/Playwright), before starting Phase 14:

- **Back button got stuck.** `login.php`/`logout.php`'s "bridge" page (sets
  `localStorage.bnb-mock-role` then redirects) used `location.href`, which
  pushes a new history entry on top of itself. Since `login.php` always
  auto-redirects an already-logged-in session away, landing back on that
  bridge entry just bounced forward again — back felt broken. Fixed by
  switching both to `location.replace()`.
- **`meal-detail.php`'s in-app Back arrow was hardcoded to `today.php`**
  whenever reached via a scheduled entry (`?entry=`), so tapping Back
  from a meal found on Calendar or Week sent you to Today instead of
  back to where you actually were. Now uses `history.back()` with a
  fallback, matching the search-originated case.

A follow-up question ("how do I edit today's menu?") surfaced a real gap
and a seed-data artifact:

- **Today had no way to add a meal at all** — parents could only assign
  meals via Week view. Today now gets the same parent-only "+ Add
  breakfast/lunch/dinner" (opens a meal-picker `<dialog>`) and a remove
  (✕) on filled slots, matching Week's pattern (`.slot-line-empty`
  styling moved from Week's inline `<style>` into `app.css` since it's
  now shared).
- Relatedly, `seed.php` only ever created *one* cycle (Sep 28 – Nov 1,
  matching the sample `Menu.jpg`), so if "today" happened to fall a few
  days before that (as it did during testing), there was no cycle at
  all covering today — Today showed an honest "Nothing planned yet" but
  even the new Add button correctly stays hidden in that case, since
  there's nowhere valid to attach the entry. Fixed by seeding a second,
  contiguous, intentionally-empty cycle immediately before it
  (`generate_cycle(2026, 8)`) so today always falls inside a real cycle
  in this dev dataset.

Verified with curl (add/remove persist, correctly attach to the cycle
that actually covers today, child/guest requests are no-ops) and a real
Playwright click-through (add via the modal, remove via the confirm
dialog, both confirmed against the database).

### Phase 14 — Polish & Hardening
A systematic pass, not tied to any one feature:

- **Critical: the live database was directly downloadable.** PHP's
  built-in server treats the whole `src/` tree as a static webroot, so
  `/data/family.sqlite` (bcrypt PIN hashes, every family member's email,
  all meal/budget data), `/lib/schema.sql`, and every `lib/*.php` /
  `scripts/*.php` source file were servable as plain static files to
  anyone who requested the URL — `scripts/` had its own CLI-only guard,
  but `data/` and `lib/` had none. Fixed with a `router.php` front
  controller (`php -S ... router.php`) that 404s `/lib`, `/scripts`,
  `/data`, and any `*.sql`/`*.sqlite` path before the built-in server
  gets a chance to serve them — verified every one now 404s while every
  real route (`.php` pages, `css/`, `js/`, `img/`, `manifest.json`)
  still works exactly as before.
- **Session cookie hardening.** Was using PHP's bare defaults (no
  `HttpOnly`, no `SameSite`). `ensure_session()` now sets `HttpOnly`,
  `SameSite=Lax`, and an auto-detected `Secure` flag (on when the
  request is actually HTTPS, so it doesn't break local plain-HTTP dev)
  before starting the session.
- **Login timing/enumeration + brute-force.** `attempt_login()` used to
  skip `password_verify()` entirely for an unrecognized email (short-
  circuit on `$user === false`), so response time leaked which emails
  belonged to real family members. Now always verifies against a
  pre-computed dummy hash when the email isn't found, and adds a 300ms
  delay on any failed attempt to slow down PIN brute-forcing.
- **CSRF / SQL injection review.** Every mutating action is already
  POST-only (no GET-based state changes) and every query is a
  parameterized PDO prepared statement — the only string-interpolated
  SQL is in CLI-only maintenance scripts (`seed.php`/`dump-db.php`)
  looping over a hardcoded table-name whitelist, never user input.
  Relying on the new `SameSite=Lax` cookie as proportionate CSRF
  protection for a private family app rather than adding per-form
  tokens across every one of the ~15 pages with forms.
- **File upload review.** `save_meal_image()` already sniffs the real
  MIME type via `mime_content_type()` (not the client-supplied header),
  generates a random server-side filename, caps size at 5MB, and only
  allows JPEG/PNG/WebP — no SVG or executable extensions possible.
  No changes needed.
- **Tap targets.** `.icon-btn`, `.fav-btn`, and `.remove-btn` were only
  ~30–35px effective touch area; bumped all three to a 44×44px minimum
  (icon size unchanged, just more invisible padding) app-wide. Left
  Week's intentionally-dense per-slot `.cooked-mini` icons alone (they
  already clear WCAG's mandatory 24×24px minimum; growing them would
  meaningfully hurt that page's deliberately compact "week at a glance"
  layout for a merely-recommended, not required, target size).
- **Keyboard focus indicators.** `.hue-slider` and `.search-box`'s
  input both set `outline: none` with no replacement, so tabbing to
  either showed no visible focus at all. Added a `:focus-visible` ring
  on the slider thumb and a `:focus-within` highlight on the whole
  search pill.
- **Color contrast audit.** Computed WCAG contrast ratios for
  text-muted against surface/background in both themes: 4.69–6.88:1,
  comfortably passing AA (4.5:1) in every case. `--border`'s contrast
  against `--surface` is low (~1.4:1) but it's a decorative/structural
  boundary, not text — left as-is rather than darkening an already
  user-approved theme unrequested.
- **Responsive QA.** Verified no horizontal overflow at 320/768/1280px
  across Today, Calendar, Shopping List, and Meal Form. Caught a real
  bug doing this: `.ingredient-row select` lacked `min-width: 0`, so at
  narrow widths the flex item wouldn't shrink below its content size
  (a long ingredient name), pushing the Qty input and remove button off
  the visible screen — the same flexbox `min-width: auto` pitfall as
  the Phase 6 calendar-grid bug. Fixed the same way.
- **Empty/error states.** Reviewed Ingredients, Meals, Search, Feedback,
  Shopping List, and Favorites — all already have sensible empty-state
  copy from earlier phases. The ingredient catalog can't currently reach
  empty (there's no delete-ingredient feature, only add/edit), so that
  edge case is unreachable and wasn't specially handled.

**Deliverable:** v1 ready for real family use. Verified with a full
Playwright regression pass after every change in this phase (all 14
pages still render correctly) plus targeted curl/Playwright checks for
each fix above (blocked paths 404, allowed paths still 200, session
cookie flags present, login timing/lockout behavior, focus rings
appearing on tab, no overflow at 320px).

### Post-launch — public repo & real deployment
Not a phase, but the work needed to actually publish and self-host this
rather than just run it in dev:

- **Demo user bootstrap.** A brand-new deploy previously had an empty
  `users` table and no way to log in at all. `lib/db.php`'s `get_db()`
  already auto-applies `schema.sql` the first time the database file
  doesn't exist; it now also calls a new `bootstrap_demo_data()` right
  after, inserting one demo Parent (`demo@example.com` / `1234`), a
  default `budget_settings` row (production had no row at all, so the
  budget form silently updated zero rows), and a menu cycle that
  actually covers today (checked against the surrounding months, since
  which `generate_cycle()` call covers "today" shifts with the
  calendar). `seed.php` still wipes and replaces all of this with fake
  family data for local dev, unaffected.
- **`src/data/` wasn't tracked in git at all** — a fresh clone would
  have no directory for SQLite to create the file in. Added
  `src/data/.gitkeep` and made `get_db()` `mkdir` it defensively either
  way.
- **The Docker image was empty.** `php-server.dockerfile` never
  `COPY`'d the app in — the only way any PHP code ever reached the
  container was `docker-compose.yml`'s `./src:/app` bind mount. Fine for
  local dev, but a published image would have been a bare PHP install
  with no app in it. Added `COPY src/ /app/` plus a root `.dockerignore`
  (excludes `data/*.sqlite`, uploaded `img/meals/*`, and non-runtime
  files like `PLAN.md`/`tools/`). The dev bind mount still overlays this
  at runtime and takes precedence, so live-editing is unaffected —
  verified by rebuilding and confirming the dev container still shows
  the seeded Ras family, not the baked-in demo account.
- **`docker-compose.prod.yml`** — deploy the published
  `ghcr.io/hermanras/bitsandbytesmenuplanner` image directly, no repo
  checkout needed, with `bnb_data`/`bnb_meal_images` named volumes so
  the database and uploaded meal photos survive `pull && up -d` across
  releases.
- **CI** (`.github/workflows/image.yml`, modeled after a working
  build/smoke/push workflow from another project): builds the image,
  then runs it **standalone** — no bind mount, no compose file, exactly
  how a GHCR pull would run it — and proves the demo-login bootstrap
  actually works against a container that has never seen this codebase
  before, and that the Phase 14 data/source exposure stays fixed
  (`/data/family.sqlite`, `/lib/*.php`, etc. all still 404). Only pushes
  to GHCR (`:latest` and `:sha-<sha>`) on a push to `main`, after all of
  the above passes.
- **`LICENSE`** (MIT) and **`README.md`** (features, screenshots, the
  demo credentials, dev vs. prod compose usage) added for the public
  repo.

Verified locally end-to-end before relying on CI to prove it for the
first time: built the image standalone, ran it with zero volumes at
all, confirmed a fresh container logs in as the demo account, reaches
`today.php`, and still 404s every blocked path — the exact sequence the
CI workflow runs.

---

## Container Reference

Following `HappyHubby`'s pattern:
- `php-server.dockerfile`: `php:8.3-cli-alpine`, `sqlite` package, runs
  `php -S 0.0.0.0:8000 -t /app /app/router.php` — the router (Phase 14)
  blocks `lib/`, `scripts/`, `data/`, and `*.sql`/`*.sqlite` from being
  served as static files, since the built-in server otherwise treats the
  entire document root as public.
- `docker-compose.yml`: single `php-server` service, `./src:/app` volume,
  port mapped to host (e.g. `8080:8000`). Dev use — bind-mounts live
  source over whatever's baked into the image.
- `docker-compose.prod.yml`: same image, pulled from GHCR instead of
  built locally, with named volumes for `data/` and `img/meals/` instead
  of a source bind mount. Real deployment.
- No frameworks unless a specific need arises (HappyHubby only pulled in
  Composer packages for web-push notifications, which this app doesn't need).
