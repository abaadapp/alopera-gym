<?php

use App\Support\ZiggyMergeScript;
use App\Support\ZiggyScript;

return [
    // Website and portal links on the club's domain, panel links on APP_URL (App\Support\ZiggyScript).
    'output' => [
        'script' => ZiggyScript::class,
        'merge_script' => ZiggyMergeScript::class,
    ],
];
