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
    'uploads_url'  => env('SHARED_UPLOADS_URL', 'https://itfa.edu.ph/enrollment/uploads'),

    // Public URL of the native ITFA app root — used for the "Back to Teacher
    // Dashboard" link, the school logo, and certificate-verify URLs. Defaults to
    // production; override with SHARED_APP_URL for a different install (e.g. local).
    'app_base_url' => env('SHARED_APP_URL', 'https://itfa.edu.ph/enrollment'),

    /*
     | LMS (Classroom) uploads — lesson documents/images, discussion images,
     | assignment attachments & submissions, announcement images. Unlike student
     | photos, these are WRITTEN by the portal, so they must live where the portal
     | itself can write AND serve them (its own domain), not the native app's.
     |
     | Defaults store them under the portal's public/lms-uploads and serve them
     | with a domain-relative URL — this works unchanged on localhost and on the
     | student.itfa.edu.ph subdomain. Override only if you want them elsewhere.
     */
    'lms_uploads_path' => env('LMS_UPLOADS_PATH', public_path('lms-uploads')),
    'lms_uploads_url'  => env('LMS_UPLOADS_URL', '/lms-uploads'),

    /*
     | Student-uploaded profile photos (the Account page). Like LMS uploads,
     | these are WRITTEN by the portal, so they must live where the portal can
     | write AND serve them on its OWN domain — otherwise, on a subdomain
     | deploy, the file is saved in one place but the shared-uploads URL points
     | at the native app's folder and the image 404s. Portal photos stay in the
     | portal; the registrar's official photos are still read from the shared
     | uploads folder as a fallback (see Portal::photoUrl).
     */
    'student_photos_path' => env('STUDENT_PHOTOS_PATH', public_path('student-photos')),
    'student_photos_url'  => env('STUDENT_PHOTOS_URL', '/student-photos'),

    /*
     | ── Super Admin maintenance console ──────────────────────────────────
     | The portal admin area (/admin) reuses the native app's staff accounts
     | (enrollment_users / user_account) — no separate password to manage.
     | Only these (normalised, lower-case) roles may sign in. Keep it to the
     | Super Admin unless you deliberately want to widen maintenance access.
     */
    'admin_roles' => array_values(array_filter(array_map(
        fn ($r) => strtolower(trim($r)),
        explode(',', env('PORTAL_ADMIN_ROLES', 'super admin'))
    ))),

    // Where database backups created from the console are written. Kept outside
    // the web root (storage/) so they are never publicly downloadable.
    'backup_path' => env('PORTAL_BACKUP_PATH', storage_path('app/backups')),

    // Path to mysqldump (leave blank to auto-detect from DB host tooling / PATH).
    'mysqldump_bin' => env('MYSQLDUMP_BIN', ''),
];
