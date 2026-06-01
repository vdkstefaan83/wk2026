# CLAUDE.md — WK2026 prediction pool

Quick-reference for Claude (and humans) maintaining this codebase. Read this before making changes; it captures the moving parts that aren't obvious from the source.

---

## Project at a glance

- **What it is**: a self-hosted prediction pool for the FIFA World Cup 2026 (48 teams, 12 groups). Each user submits one or more "prediction forms" with group-stage scores, knockout picks, top scorer, and a tiebreaker. Scoring runs automatically as real results come in via API.
- **URL on the host**: `https://www.psb.ugent.be/wk2026`
- **Deployment path**: `/nas6/root/psb/web/public/psbweb01/wk2026/` (Apache sees it via bind-mount at `/var/www/html/public/wk2026/`)
- **Repo**: `git@github.com:vdkstefaan83/wk2026.git` (main branch)

## Tech stack

| Layer | Choice |
|---|---|
| PHP | 8.1+, custom mini-MVC (no framework) |
| Routing | Bramus Router — **must use `'Class@method'` string syntax**, not array callables |
| Templates | Twig 3 (`auto_reload=true`, `cache=storage/cache/twig`) |
| Frontend | Tailwind CSS via CDN + Alpine.js 3 (defer) |
| DB | MySQL/MariaDB (PDO), single schema in `migrations/001_schema.sql` |
| Mail | PHPMailer, SMTP from `.env` |
| PDF | mPDF (single-page A4 landscape) |
| QR | endroid/qr-code v5 — EPC069-12 SEPA payload |
| Auth | DB (bcrypt) or Keycloak OIDC, toggle via `settings.auth_provider` |
| Live data | `MatchDataProvider` interface; backends: ApiFootballProvider, FootballDataOrgProvider (default — free tier covers WC2026) |

## Directory layout

```
WK2026/
├── public/                Apache DocumentRoot
│   ├── index.php          front controller
│   ├── .htaccess          rewrite + security headers
│   └── assets/{js,css}    Tailwind extras + prediction.js wizard
├── src/
│   ├── Core/              App, Config, Database, Auth, Session, View, Controller,
│   │                      Validator, Mailer, PdfGenerator, KeycloakClient,
│   │                      QrCodeService, Setting
│   ├── Controllers/       Home, Auth, Dashboard, Prediction, Api, Admin
│   └── Services/
│       ├── FifaRankingService.php       group standings with FIFA tiebreakers
│       ├── KnockoutBracketService.php   official 2026 R32 matrix + feeders
│       ├── PredictionResolver.php       computes a user's resolved bracket
│       ├── ScoringService.php           points per round + topscorer bonus
│       ├── MatchSyncService.php         provider-agnostic API sync
│       ├── MatchDataProvider.php        interface
│       └── Providers/
│           ├── ApiFootballProvider.php
│           └── FootballDataOrgProvider.php   <- default
├── templates/             Twig (layout, home, auth, dashboard, prediction,
│                          admin, errors, partials)
├── migrations/            schema + per-feature PHP migrators (idempotent)
├── config/routes.php      Bramus router definitions
├── bin/                   CLI scripts (sync_matches, debug_provider)
└── storage/               pdfs/, qrcodes/, cache/, logs/
```

## Conventions to keep in mind

- **All user-facing strings are English** (templates + controller flash messages + Validator + Auth exceptions). Don't reintroduce Dutch.
- **Bramus Router** does `stripos($handler, '@')` — registering `[Class::class, 'method']` crashes. Always: `Class::class . '@method'`.
- **Twig data into Alpine `x-data`**: never put JSON directly inside a double-quoted attribute. Hoist to `<script>window.__var = …json_encode|raw}</script>` and reference from x-data.
- **OPcache** can serve stale bytecode after a `git pull`. Restart Apache (`systemctl restart apache2`) when behaviour disagrees with disk content. CLI is unaffected.
- **Apache config** does not currently `grep` for `wk2026` — the vhost lives outside `/etc/{apache2,httpd}`. The DocumentRoot resolves to the `/nas6/` path via bind-mount; reflection in code shows `/var/www/html/public/wk2026/`.
- Default font/style: white background, emerald primary, vibrant sport-app feel (`.card`, `.banner-green/-orange/-blue/-pink`, `.pill-green/-orange/-blue/-pink/-grey`, `.btn .btn-primary/-secondary/-danger`).
- **Score & numeric inputs**: always `type="number" min="0" step="1" inputmode="numeric"`. JS strips non-digits as the user types. Backend clamps via `max(0, (int)$val)` as the final safety net.
- **Knockout bracket**: do **not** improvise pairings — use the canonical FIFA 2026 R32 structure in `KnockoutBracketService::R32` and the FEEDERS map. The browser JS mirrors the same structure for live preview.
- **Accessibility**: every interactive input has an `aria-label` (short name) or `aria-describedby` (long context). Flag emojis are `aria-hidden="true"`.

## Authentication

Two modes, switched in `/admin/settings`:

1. **DB** — email + bcrypt password. Registration at `/register`.
2. **Keycloak** — OIDC via league/oauth2-client (GenericProvider). Configure `KEYCLOAK_*` env vars; redirect URI `{APP_URL}/auth/keycloak/callback`.

Users are auto-created on first Keycloak login. Admin flag is **never auto-granted** — promote via `/admin/users` (toggle) or by SQL: `UPDATE users SET is_admin = 1 WHERE email = '…'`.

`Auth::attemptDb()` and `upsertFromOidc()` both write `last_login_at` so `/admin/users` shows accurate "last login" data.

## Database schema highlights

`migrations/001_schema.sql` defines everything. Notable columns added later (apply via the matching `migrate_*.php` for existing installs):

- `forms.topscorer_custom_name` — legacy, kept null since the UI no longer allows free-text
- `forms.tiebreaker_value` — INT NULL, required at submit
- `settings.prize_distribution`, `payment_recipient_name`, `tiebreaker_question`, `tiebreaker_correct_value`, `actual_topscorer_player_id`, `predicted_topscorer_goals_for_*`, `last_sync_at`, `last_sync_summary`, `last_topscorer_sync_at`

Form lifecycle: `draft → submitted → paid (via admin)`. Submitted+paid forms are **locked** — backend refuses deletion at both `/predictions/{id}/delete` and `/admin/forms/{id}/delete`.

## Routes (selected)

- `GET  /`, `/login`, `/register`, `/logout`
- `GET  /auth/keycloak/{login,callback}`
- `GET  /dashboard`
- `GET  /predictions/new`, `POST /predictions/new`
- `GET  /predictions/{id}` (wizard), `POST /…/save`, `POST /…/submit`, `POST /…/delete`
- `GET  /predictions/{id}/pdf` — rebuilds the PDF on every hit (no-cache)
- `POST /api/predictions/{id}/autosave`, `GET /api/predictions/{id}/state`, `GET /api/players?q=`
- `GET  /admin`, `/admin/{settings,email-templates,teams,players,matches,forms,leaderboard,users}`
- `POST /admin/sync-matches` (provider sync) and `/admin/sync-debug` (dump first fixture)
- `GET  /admin/forms/{id}/pdf` — admin can read any form's PDF

`bin/sync_matches.php` is the cron entry point. Output:
```
[YYYY-MM-DD HH:MM:SS] provider=football-data.org updated=N finals_recomputed=0|1 topscorer=...
```

## Scoring (`src/Services/ScoringService.php`)

| Item | Points |
|---|---|
| Group match: correct 1-X-2 | 1 |
| Group match: exact score (in addition) | +2 |
| Round of 32: per correct team | 5 |
| Round of 16: per correct team | 10 |
| Quarter-finals: per correct team | 15 |
| Semi-finals: per correct team | 25 |
| Finalist: per correct team | 50 |
| Champion (winner of F-01) | 100 |
| Top scorer: correct player | 10 |
| Top scorer: per goal scored | +3 |

Tiebreaker: when `settings.tiebreaker_correct_value` is filled, the leaderboard sort breaks ties by `|jouw_antwoord − correct|`.

`forms.score` is recomputed after every sync that updates a match to FT/AET/PEN, **and** after every topscorer-data update.

## Live data sync

Two providers behind a shared `MatchDataProvider` interface:

- **football-data.org** (default, free) — token at https://www.football-data.org/client/register
- **api-football.com** (Pro plan needed for WC2026, free tier doesn't cover it)

Choose via env: `MATCH_DATA_PROVIDER=football_data_org` (default) or `api_football`.

`MatchSyncService::sync()`:
1. Calls `provider->fixtures()` and updates `actual_*_goals` on matches that changed.
2. Calls `provider->topScorers()` (throttled to once per hour by default).
3. Writes `settings.last_sync_at` + JSON summary.
4. Calls `ScoringService::recomputeAll()` when any final-status match changed or the topscorer goal counts moved.

**Knockout placeholders** with empty teams (TBD before group stage finishes) are silently skipped — they're not errors.

## Common operations

### Fresh install
```bash
composer install
cp .env.example .env       # edit DB, MAIL_*, KEYCLOAK_* if needed
mysql -e "CREATE DATABASE wk2026 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php migrations/migrate.php
php migrations/seed_players.php       # ESPN squads (28 of 48)
php migrations/seed_real_schedule.php # KPN kick-off times
```

### After `git pull` on existing install
```bash
composer install --no-dev --optimize-autoloader
# apply pending feature migrations if not run yet (each is idempotent):
php migrations/migrate_add_topscorer_tiebreak.php
php migrations/migrate_add_prize_distribution.php
php migrations/migrate_add_qr_payment.php
php migrations/migrate_to_english.php   # one-shot if you originally seeded Dutch
sudo systemctl restart apache2          # OPcache reset
```

### Cron (live during the tournament)
```cron
*/15 * * * * cd /var/www/html/public/wk2026 && /usr/bin/php bin/sync_matches.php >> storage/logs/sync.log 2>&1
0 22 * * *   cd /var/www/html/public/wk2026 && /usr/bin/php bin/sync_matches.php --topscorer >> storage/logs/sync.log 2>&1
```

### Diagnostics
- `php bin/debug_provider.php` — print which provider is loaded + first fixture + first 3 top scorers
- Admin → `/admin/matches` → 🔍 **Debug** button — same but in web context (uncovers OPcache / vhost mismatches via Reflection's `getFileName()`)

## Payment + QR

When `settings.payment_iban` is filled:
- After submit, an EPC069-12 SEPA QR PNG is generated to `storage/qrcodes/form-{id}.png`
- It's embedded inline (`cid:wk2026qr`) in the user's confirmation email
- It's also shown on the prediction page while status is submitted + unpaid
- Reference format: `"{User name} - {Form label}"` (max 140 chars, sanitized)

Beneficiary name comes from `settings.payment_recipient_name` (full bank name), falling back to `settings.payment_recipient` (short display name).

## Things future-you will want to remember

- **Don't paste secrets into chat** — earlier in development an API key was shared accidentally. Always treat keys as one-shot.
- **`prediction.js` defines a fallback** at the top of `edit.twig` so the wizard still renders if the external JS fails — keep both in sync if you change the data shape.
- **The wizard config** flows server → `window.__wizardConfig` → Alpine `predictionWizard()`. JSON in `x-data` attributes will silently break on apostrophes/quotes — always go via the script block.
- **Twig partial for prizes** is `templates/partials/prizes.twig`. It expects `settings.prize_distribution` as plain text, one `"Label: Prize"` per line.
- **PDF is rebuilt on every view**. It's safe because submitted forms are immutable. If perf matters later, cache `pdf_path` and invalidate when scoring data changes.
- **The R32 matrix** (`KnockoutBracketService::R32`) is FIFA's published bracket for the 48-team format — don't change without an authoritative source.
- **football-data.org** uses `tla` (three-letter abbreviation) which maps to our `teams.iso3` column — DB seed (002_groups_teams.sql) uses the same codes (MEX, BEL, etc.).
