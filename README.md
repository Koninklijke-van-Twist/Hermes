# Hermes

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gezet lezen gewone page-loads (`index.php` / `dashboard_data.php`) eerst de lokale nightly-filecache. Mímir (of de BC-fallback) gaat alleen mee bij een cache-miss, bij `refresh=1` op de retry-knop, of tijdens `nightly.php` (CLI via `php web/nightly.php`, of dezelfde script-URL via cron). Nightly en retry gebruiken de lange timeout; een gewone load blijft kort.

Faalt die aanroep (verbinding/timeout, HTTP 5xx, auth, ongeldige JSON of een Mímir-foutpayload), dan haalt Hermes dezelfde data op via het oude Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over. Een BC-semantische 4xx die Mímir alleen doorgeeft (OData `error.code` zoals `Internal_RecordNotFound`) opent dat circuit niet. Een sectie die al rijen in de nightly-cache heeft, houdt die rijen. Zonder eerdere geslaagde fetch schrijft Hermes een lege cache met `fetched=false`. Het dashboard toont dan de BC-fout en geen lege succes-card. Nightly gaat door naar de volgende sectie. Een geslaagde nightly of `refresh=1` overschrijft die lege cache.

Draait nightly al, dan toont `nightly.php` (en `?status=1` of `php web/nightly.php --status`) PID, lockpad met leeftijd, starttijd, huidige bedrijf/sectie/bron, voortgang, laatst afgeronde sectie, laatste fout en of het proces nog leeft. Een dood PID met vrije lock wordt bij de volgende start opgeruimd. `?force=1` start niet een tweede run zolang dat PID leeft; stop het proces met `kill` en wis het lockbestand niet terwijl het nog draait.

Laat die BC-credentials in `auth.php` naast `$mimirApi` staan. `index.php`, `dashboard_data.php` en `nightly.php` laden `auth.php` al, zodat de fallback ze kan gebruiken. Ontbreken ze, dan wordt de oorspronkelijke Mímir-fout doorgegeven. Zonder `$mimirApi` blijft het bestaande BC-pad ongewijzigd.
