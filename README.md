# Hermes

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gezet lezen gewone page-loads (`index.php` / `dashboard_data.php`) eerst de lokale nightly-filecache. Mímir (of de BC-fallback) gaat alleen mee bij een cache-miss, bij `refresh=1` op de retry-knop, of tijdens `nightly.php` (CLI via `php web/nightly.php`, of dezelfde script-URL via cron). Nightly en retry gebruiken de lange timeout; een gewone load blijft kort.

Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Hermes dezelfde data op via het oude Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over.

Laat die BC-credentials in `auth.php` naast `$mimirApi` staan. `index.php`, `dashboard_data.php` en `nightly.php` laden `auth.php` al, zodat de fallback ze kan gebruiken. Ontbreken ze, dan wordt de oorspronkelijke Mímir-fout doorgegeven. Zonder `$mimirApi` blijft het bestaande BC-pad ongewijzigd.
