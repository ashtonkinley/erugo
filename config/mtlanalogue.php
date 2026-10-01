<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Montréal Analogue lab settings
    |--------------------------------------------------------------------------
    |
    | bestof_path: read-only mount of the lab's BestOf archive inside the
    | container. Each month folder (e.g. 11_BestOfMay) may contain a _10Best
    | subfolder; photos found there are synced into the rotating landing-page
    | backgrounds by the SyncBestOfBackgrounds job. Months without a _10Best
    | folder are skipped, so the sync degrades gracefully until the lab
    | publishes that month's selection.
    |
    */

    'bestof_path' => env('BESTOF_PATH', '/bestof'),

];
