<?php
return [
    'folder_id' => env('DRIVE_FOLDER_ID', ''),
    'credentials' => env('DRIVE_SERVICE_ACCOUNT_JSON', 'storage/app/private/google-service-account.json'),
];
