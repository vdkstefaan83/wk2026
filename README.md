# WK2026 Predictions

Een PHP 8+ web-applicatie waarmee gebruikers voorspellingen indienen voor het FIFA WK 2026 (48 teams, 12 groepen). Bevat live FIFA-ranking per groep, automatische knock-out bracket (incl. beste derdes), PDF-export, e-mail flow en een admin-paneel.

## Tech stack

- PHP 8.1+
- Custom MVC met [Bramus Router](https://github.com/bramus/router)
- [Twig 3](https://twig.symfony.com/)
- MySQL/MariaDB via PDO
- Tailwind CSS (CDN) + Alpine.js
- Quill 2.x voor WYSIWYG e-mailtemplates
- PHPMailer (SMTP)
- mPDF voor PDF-generatie
- league/oauth2-client voor Keycloak OIDC

## Mappenstructuur

```
WK2026/
├── public/             # web root (DocumentRoot)
│   ├── index.php       # front controller
│   ├── .htaccess       # rewrite rules
│   └── assets/
├── src/
│   ├── Core/           # framework klassen
│   ├── Controllers/
│   └── Services/       # FIFA ranking, bracket, scoring, resolver
├── templates/          # Twig templates
├── migrations/         # SQL schema + PHP runner
├── config/routes.php
├── storage/            # logs, cache, pdfs
└── composer.json
```

## Installatie

```bash
composer install
cp .env.example .env
# vul .env in (DB, MAIL_*, evt. KEYCLOAK_*)

# DB aanmaken
mysql -uroot -p -e "CREATE DATABASE wk2026 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# Migratie + seed
php migrations/migrate.php

# Lokaal draaien:
php -S 127.0.0.1:8080 -t public
```

Open http://127.0.0.1:8080 en log in als beheerder:

- e-mail: `admin@psb.ugent.be`
- wachtwoord: `admin`

> Verander dit wachtwoord meteen, of registreer een eigen account en flag dat als admin in de DB (`UPDATE users SET is_admin = 1 WHERE email = '...'`).

### Apache vhost (productie)

`DocumentRoot` op `/path/to/WK2026/public`. `mod_rewrite` aanzetten. De meegeleverde `.htaccess` doet de rest.

## Authenticatie

Via **Admin → Instellingen → Authenticatie** schakel je tussen:

- **db** — registratie + login via e-mail en wachtwoord (default).
- **keycloak** — alle login-pogingen verlopen via OIDC. Vereist dat `KEYCLOAK_ENABLED=true` staat in `.env` én alle `KEYCLOAK_*` waarden ingevuld zijn.

OIDC callback-URL die je in Keycloak moet registreren komt overeen met `KEYCLOAK_REDIRECT_URI`.

## Scoring-regels

Geconfigureerd in `src/Services/ScoringService.php`:

| Onderdeel | Punten |
|---|---|
| Correcte 1-X-2 in groepswedstrijd | **1** |
| Correcte exacte score (extra bovenop bovenstaande) | **+2** |
| Correct land in 1/16 (R32) | **5** |
| Correct land in 1/8 (R16) | **10** |
| Correct land in 1/4 (QF) | **15** |
| Correct land in 1/2 (SF) | **25** |
| Correct land in Finale | **50** |
| Wereldkampioen | **100** |
| Topscorer (juiste speler) | **10** |
| Per goal die jouw topscorer maakt | **+3** |

De werkelijke topscorer en zijn goalsaldo zet je via **Admin → Instellingen** (zie keys `actual_topscorer_player_id` en `predicted_topscorer_goals_for_<player_id>`). Deze laatste maakt het mogelijk om elke voorspelde-speler-specifieke goalsom te vergoeden, ongeacht of hij topscorer werd.

## FIFA-rangschikking

`src/Services/FifaRankingService.php` past de officiële FIFA-tiebreakers toe:

1. Punten
2. Doelsaldo
3. Doelpunten voor
4. Onderling resultaat (punten → DS → DV) tussen twee gelijkstaande teams
5. Bij drie of meer gelijke teams: onderling klassement (mini-tabel)

## Knock-out (R32 → finale)

`src/Services/KnockoutBracketService.php` selecteert de **8 beste derdes** uit 12 groepen op punten/DS/DV en koppelt ze deterministisch aan de groepswinnaars. Same-group rematches worden vermeden in de R32. Downstream (R16 → finale) propageert het pad: slot R16-01 wordt gevoed door R32-01 en R32-02, enzovoort.

> ⚠️ Het officiële FIFA seedings-matrix voor de R32 in het 48-team formaat is nog niet vrijgegeven op het moment van schrijven. De huidige pairings volgen een "sterkste winnaar krijgt zwakste derde" benadering. Pas dit aan in `KnockoutBracketService::pairR32()` zodra FIFA het officiële schema publiceert.

## Workflow voor de gebruiker

1. Inloggen / registreren.
2. Dashboard → "+ Nieuwe voorspelling" (meerdere formulieren per gebruiker zijn toegestaan).
3. In de wizard:
   - **Groepsfase** — scores ingeven; ranking en knock-out bracket updaten live.
   - **R32 / R16 / QF / SF / F** — verschijnen zodra alle groepswedstrijden ingevuld zijn; klik per match het doorgaande land aan.
   - **Topscorer** — kies team-kampioen en topscorer.
   - **Samenvatting** — "Bewaren als concept" of "Definitief verzenden".
4. Bij verzenden: PDF wordt opgeslagen in `storage/pdfs/` en gemaild naar de gebruiker + `MAIL_ADMIN_ADDRESS`. De gebruiker krijgt de betaal-instructies te zien.
5. Een concept-formulier autosavet bij elke wijziging (debounced, naar `/api/predictions/:id/autosave`).

## Admin-paneel

- `/admin/settings` — auth-keuze, deadlines, bedrag, IBAN, instructies.
- `/admin/email-templates` — verzendmail naar gebruiker en admin, bewerkbaar met Quill.
- `/admin/teams` — pas namen, vlaggen en groepsindeling aan (bij correctie na officiële loting).
- `/admin/players` — beheer topscorer-keuzelijst.
- `/admin/matches?stage=group|r32|...` — vul aftrap, locatie en **werkelijke score** in. Op basis hiervan berekent `ScoringService` punten.
- `/admin/forms` — overzicht ingediende voorspellingen, betaal-status afvinken.
- `/admin/leaderboard` — score-tabel; "Herberekenen" recomputed alle ingediende formulieren.

## API

| Methode | Pad | Doel |
|---|---|---|
| POST | `/api/predictions/{id}/autosave` | JSON `{scores, slots, winner_team_id, topscorer_player_id, label, _csrf}` |
| GET | `/api/predictions/{id}/state` | Gestructureerde JSON met standings + bracket + picks |
| GET | `/api/players?q=…` | Speler-zoek (typeahead) |

## Live scores via API-Football

`src/Services/MatchSyncService.php` haalt wedstrijduitslagen en de topscorer op uit [API-Football](https://www.api-football.com/) en mapt ze op de lokale `matches`/`players` rijen via ISO3-code (of naam-fallback).

**Setup:**
1. Maak een gratis account op api-football.com en kopieer je key.
2. Vul in `.env`:
   ```env
   API_FOOTBALL_KEY=jouw_key
   API_FOOTBALL_LEAGUE_ID=1     # 1 = FIFA World Cup
   API_FOOTBALL_SEASON=2026
   ```
3. Test handmatig:
   ```bash
   php bin/sync_matches.php
   ```
4. Of via de admin UI: knop **"↻ Sync via API-Football"** bovenaan `/admin/matches`. Vink "+ topscorer" aan om de topscorer-data te forceren (anders wordt die om de 6 uur ververst om de gratis quotum te respecteren).

**Cron-opzet** (elke 15 min een lichte sync, free-tier vriendelijk):
```cron
*/15 * * * *  www-data  cd /var/www/html/public/wk2026 && /usr/bin/php bin/sync_matches.php >> storage/logs/sync.log 2>&1
0 22 * * *    www-data  cd /var/www/html/public/wk2026 && /usr/bin/php bin/sync_matches.php --topscorer >> storage/logs/sync.log 2>&1
```

De synchronisatie:
- update `actual_home_goals`/`actual_away_goals` per gewijzigde match;
- triggert `ScoringService::recomputeAll()` zodra een match op FT/AET/PEN staat;
- bewaart laatste sync-tijdstip + summary in `settings` (zichtbaar op `/admin/matches`);
- bewaart de actuele topscorer in `settings.actual_topscorer_player_id` + per-speler goal-tellingen in `predicted_topscorer_goals_for_<id>` zodat de "3 ptn per goal van jouw voorspelde topscorer"-regel correct telt, ook als die speler niet de uiteindelijke topscorer is.

## Veelgestelde aanpassingen

- **Wijzig bedrag** → admin → Instellingen → "Bedrag".
- **Wijzig mail-content** → admin → E-mail templates → bewerk in Quill. Placeholders zoals `{{user_name}}`, `{{payment_amount}}`, `{{form_label}}` worden bij verzenden vervangen.
- **Officiële loting corrigeren** → admin → Teams & groepen (verschuif teams), daarna admin → Wedstrijden (vul juiste home/away in als je groep-wijziging dat noodzaakt).
- **Werkelijke uitslagen invoeren** → admin → Wedstrijden → per stage.

## Bekende beperkingen

- Initiële seed gebruikt **placeholder-loting**; corrigeer via admin zodra de echte FIFA-loting bevestigd is.
- Same-group avoidance in R32 is heuristisch (één keer swappen). Bij ongebruikelijke uitkomsten kan er nog een clash overblijven — dit corrigeer je dan via een aanpassing in `KnockoutBracketService`.
- E-mail templating gebruikt enkel `{{var}}` placeholders (geen full Twig in het admin-veld) om gebruikersinvoer veilig te houden.

## Licentie

Intern PSB / VIB-UGent gebruik.
