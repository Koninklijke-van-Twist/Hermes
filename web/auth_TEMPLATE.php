<?php
$auth_list =
    [
        "env1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env2" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env3" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD']
    ];
$environment = "env1";
$auth = $auth_list[$environment];
$baseUrl = "https://my-bc-domain.com:7148/";

// Optioneel: alle OData-reads (inclusief company-discovery) via Mímir.
// Zonder $mimirApi blijft het bestaande BC-pad actief ($auth_list / $environment / $baseUrl / $auth).
// Niet committen: zet dit in web/auth.php op de server.
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

$allowedUsers = [
    "user@domain.nl"
];