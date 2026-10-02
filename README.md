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

`Internal_RecordNotFound` / `Internal_DataNotFoundFilter` op een datumfilter (order intake, inkoopregels) komt uit de BC-pagina zelf: die zoekt een gerelateerde leverancier of projectplanningsregel op en breekt de hele reeks af als die ontbreekt. Hermes stuurt die nummers niet. Nightly en `refresh=1` splitsen de reeks en slaan alleen een venster van minder dan twee dagen over; de overige rijen blijven een geslaagde fetch. Raakt het splitbudget (48 aanroepen) op, dan gaat de oorspronkelijke BC-fout door en wordt een deels geslaagde samenvoeging niet als `fetched=true` bewaard. Een reeks zonder enkele leesbare rij, een bron zonder datumfilter, en elke andere fout (timeout, HTTP 5xx, auth, andere 4xx) blijven een sectiefout. Klant- en artikelkaarten worden niet per sleutelnummer opgezocht.

### Minder rijen bij een lokale test

Zonder vlag haalt elke bron de hele historie op. Zet een plafond per bron (elke card die die bron gebruikt krijgt dezelfde cap) via één van deze, in deze volgorde:

1. `?dev_top=50` op het dashboard (`index.php`) of op `nightly.php`. Het dashboard geeft die parameter door aan elke card-aanvraag.
2. `php web/nightly.php --dev-top=50`
3. omgeving `HERMES_DEV_TOP=50` (hetzelfde patroon als `DEMETER_DEBUG_ODATA`)
4. `$hermesDevTop = 50;` in `web/auth.php` (niet committen; zie `web/auth_TEMPLATE.php`)

`0` of weglaten is ongelimiteerd. Het plafond gaat als `$top` / Mímir-`top` mee en zit in de cache-URL, zodat een gelimiteerde fetch de volledige nightly-cache niet overschrijft. Een gelimiteerde aanvraag splitst een `RecordNotFound` niet verder: de test moet kort blijven.

Laat die BC-credentials in `auth.php` naast `$mimirApi` staan. `index.php`, `dashboard_data.php` en `nightly.php` laden `auth.php` al, zodat de fallback ze kan gebruiken. Ontbreken ze, dan wordt de oorspronkelijke Mímir-fout doorgegeven. Zonder `$mimirApi` blijft het bestaande BC-pad ongewijzigd.
