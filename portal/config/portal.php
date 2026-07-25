<?php

return [
    /*
     | Where the native ITFA app keeps uploaded files (student photos, teacher
     | signatures). The portal reads/writes the SAME folder so photos stay in
     | sync across both apps.
     |
     |  - As a SUBFOLDER of the native app (default): the parent app's /uploads.
     |  - As a SEPARATE SUBDOMAIN: point these at a shared/synced uploads location
     |    (shared disk, or an https URL served by the main app).
     */
    'uploads_path' => env('SHARED_UPLOADS_PATH', base_path('../uploads')),
    'uploads_url'  => env('SHARED_UPLOADS_URL', 'http://localhost/enrollment/uploads'),

    // Public URL of the native app root (for the school logo on print docs).
    'app_base_url' => env('SHARED_APP_URL', 'http://localhost/enrollment'),
];
