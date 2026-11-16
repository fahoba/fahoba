<?php

declare(strict_types=1);

/*
 * The server's app registrations — one per service, made once by the
 * operator. A service without its keys is not offered at all. The redirect
 * address to register there is
 * `<APP_URL>/auth/photos/fahoba/cloud-photos-<service>/callback`.
 */
return [
    'onedrive' => [
        'client_id' => env('CLOUD_PHOTOS_ONEDRIVE_CLIENT_ID'),
        'client_secret' => env('CLOUD_PHOTOS_ONEDRIVE_CLIENT_SECRET'),
    ],
    'dropbox' => [
        'client_id' => env('CLOUD_PHOTOS_DROPBOX_APP_KEY'),
        'client_secret' => env('CLOUD_PHOTOS_DROPBOX_APP_SECRET'),
    ],
];
