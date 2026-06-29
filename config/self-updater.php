<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Update Server Configuration
    |--------------------------------------------------------------------------
    |
    | The URL where the system will check for the latest version.json file.
    | E.g., 'https://version-update.snawbar.cloud/updater/version.json'
    |
    */
    'update_url' => env('SELF_UPDATER_UPDATE_URL', config('system.update_url')),

    /*
    |--------------------------------------------------------------------------
    | Current System Version
    |--------------------------------------------------------------------------
    |
    | The current version of this application if not defined in the database.
    |
    */
    'version' => env('SELF_UPDATER_VERSION', config('system.version', '1.0.0')),

    /*
    |--------------------------------------------------------------------------
    | License Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for checking and writing licenses to your central license
    | manager server.
    |
    */
    'license_url' => env('SELF_UPDATER_LICENSE_URL', config('license.url')),
    'license_write_url' => env('SELF_UPDATER_LICENSE_WRITE_URL', config('license.write_url')),
    'license_secret' => env('SELF_UPDATER_LICENSE_SECRET', config('license.secret')),
    'license_local_path' => env('SELF_UPDATER_LICENSE_LOCAL_PATH', config('license.local_path', base_path('license.json'))),

];
