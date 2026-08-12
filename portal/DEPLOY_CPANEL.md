# Deploy the Student Portal to `student.itfa.edu.ph` (cPanel, PHP 8.1)

The portal was downgraded to **Laravel 10**, which runs on **PHP 8.1** — so set the
subdomain to **`ea-php81`** in MultiPHP Manager (it already has pdo_mysql, mbstring,
bcmath and fileinfo enabled, which alt-php82 was missing).

> ⚠ **Re-upload the whole `portal/` folder** for this change — `vendor/` was fully
> rebuilt for Laravel 10, and the framework skeleton files changed (`artisan`,
> `bootstrap/app.php`, `public/index.php`, `app/Http/Kernel.php`, `app/Console`,
> `app/Exceptions`, `app/Providers`, several `config/*`). Uploading only part will
> mix Laravel 10 and 12 files and crash. Do **not** upload your local `.env`.

## 1. Upload the app

Upload the **entire `portal/` folder** to your account, e.g. to
`/home/thrgd534/portal_app` (NOT inside `public_html`). Include `vendor/` so you
don't need Composer on the server.

**Do NOT upload:** `.env` (make a fresh one — step 3), `node_modules/`,
`storage/logs/*.log`, `storage/framework/{cache,sessions,views}/*` (keep the
folders + their `.gitignore`, just not the cached files).

## 2. Point the subdomain at Laravel's `public/`

Laravel must be served from its `public/` directory only.

- **Preferred:** cPanel → *Domains* → `student.itfa.edu.ph` → edit **Document Root**
  to `/home/thrgd534/portal_app/public`.
- **If the doc root can't be changed** (it's fixed to `…/student.itfa.edu.ph`):
  move the **contents of `portal_app/public/`** into that doc root, keep the rest
  of the app in `portal_app/`, and edit the doc root's `index.php` so the two
  `require` paths point up to `../portal_app/vendor/autoload.php` and
  `../portal_app/bootstrap/app.php`.

The existing `public/.htaccess` handles the URL rewriting either way.

## 3. Create `.env`

Copy `.env.example` → `.env` and set:

```
APP_ENV=production
APP_DEBUG=true                 # ← TRUE while deploying so errors show; set false when done
APP_URL=https://student.itfa.edu.ph
APP_KEY=                       # ← generate: see step 4

DB_HOST=localhost
DB_DATABASE=thrgd534_grading
DB_USERNAME=thrgd534_grading
DB_PASSWORD=<your live DB password>
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_general_ci

# Native app links (student photos, school logo, certificate QR verify):
SHARED_UPLOADS_URL=https://itfa.edu.ph/uploads
SHARED_APP_URL=https://itfa.edu.ph
# Subdomain can't reach ../uploads on disk — either sync the folder and set:
# SHARED_UPLOADS_PATH=/home/thrgd534/public_html/uploads
```

## 4. Generate the app key

If you have cPanel **Terminal / SSH**:
```
cd /home/thrgd534/portal_app
/opt/cpanel/ea-php82/root/usr/bin/php artisan key:generate
/opt/cpanel/ea-php82/root/usr/bin/php artisan config:clear
/opt/cpanel/ea-php82/root/usr/bin/php artisan cache:clear
```
No terminal? Put any 32-char random string through base64 and set
`APP_KEY=base64:<value>` manually (must be exactly 32 bytes before encoding).

## 5. Permissions

Make these writable (cPanel File Manager → Permissions, or `chmod`):
```
storage/                 → 755 (recursive)
bootstrap/cache/         → 755
```

## 6. SSL

cPanel → *SSL/TLS Status* → run **AutoSSL** for `student.itfa.edu.ph` so HTTPS works
(the app forces https in production).

## 7. Verify, then lock down

1. Visit **https://student.itfa.edu.ph/deploy-check.php** — every row should be ✅.
   (Any ❌ tells you exactly what's wrong; a blank page means PHP itself failed —
   check the subdomain's PHP version and `.htaccess`.)
2. Load **https://student.itfa.edu.ph/login** and sign in with a student LRN.
3. When it works:
   - **DELETE `public/deploy-check.php`** (it exposes server/DB info).
   - Set **`APP_DEBUG=false`** in `.env`, then `php artisan config:clear`.

## Notes
- The portal creates **no new tables** — it reads the shared `thrgd534_grading` DB
  (including the `classroom_*` LMS tables if you ran `lms_live_migration.sql`).
- Teacher SSO from the native app needs `CLASSROOM_PORTAL_URL=https://student.itfa.edu.ph`
  set in the **native app's** environment (not here).
