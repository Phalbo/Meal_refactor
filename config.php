<?php
// Configurazione globale. Nessun login, nessuna sessione.
// family_id e user_id sempre 1, hardcodati.

define('DB_PATH',   __DIR__ . '/data/meal.db');
define('APP_NAME',  'Meal Planner');
define('FAMILY_ID', 1);
define('USER_ID',   1);

define('INTOLERANCE_PRESETS', [
    'lattosio', 'glutine', 'nichel', 'uova', 'peperoni',
    'arachidi', 'frutta secca', 'crostacei',
]);

define('PORTION_ADULT', 1.0);
define('PORTION_CHILD', 0.6);

define('ZONE_ORDER', [
    'ortofrutta' => 1,
    'pane'       => 2,
    'macelleria' => 3,
    'pesce'      => 4,
    'latticini'  => 5,
    'scaffali'   => 6,
    'bevande'    => 7,
    'surgelati'  => 8,
    'casalinghi' => 9,
    'altro'      => 10,
]);
