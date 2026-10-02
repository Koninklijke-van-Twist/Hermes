<?php
/**
 * Auth-template voor Hermes.
 *
 * Mímir heeft de voorkeur. Laat het Business Central-blok hieronder staan:
 * dat is de automatische fallback als Mímir uitvalt. $auth_list, $environment,
 * $auth en $baseUrl moeten in web/auth.php naast $mimirApi blijven staan.
 *
 *   $mimirApi  = 'mimir_…';  // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Met $mimirApi gaan reads eerst naar Mímir en bij een fout naar het BC-blok.
 * Zonder $mimirApi wordt alleen het BC-blok gebruikt.
 */

// --- Mímir (aanbevolen) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (direct pad, en fallback als Mímir faalt) ---
$auth_list =
    [
        "env1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env2" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env3" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD']
    ];
$environment = "env1";
$auth = $auth_list[$environment];
$baseUrl = "https://my-bc-domain.com:7148/";

$allowedUsers = [
    "user@domain.nl"
];

// Lokaal testen. Max rijen per OData-bron; elke card die die bron laadt stopt
// daar. Productie: deze regel weglaten. Zelfde effect als HERMES_DEV_TOP,
// ?dev_top=50 op het dashboard of php web/nightly.php --dev-top=50.
// De limiet zit in de cache-URL en overschrijft de volledige nightly-cache niet.
// $hermesDevTop = 50;
