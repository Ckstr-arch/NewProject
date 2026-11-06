# Deploy PHP/MySQL on a real host

Deploy the same existing frontend and PHP API on one HTTPS origin. This guide describes the required hosting arrangement; it does not claim that a public server, TLS certificate, macOS installation or Apache configuration was exercised during the cloud implementation. See [TEST_RESULTS.md](TEST_RESULTS.md) for actual evidence.

## Prepare the host

Use PHP 8.2+ with `PDO`, `pdo_mysql`, `mbstring`, native sessions and supported password hashing, genuine MySQL 8.0.16+, and a PHP-compatible Apache or Nginx/PHP-FPM service. Verify the web PHP installation as well as CLI PHP; they can load different versions/extensions. No Composer, Node or Python process is required to run the application. Optional local container helpers and browser tools are development/test infrastructure.

Install the reviewed repository release in a private application directory, such as `/srv/sportshub`. The backend document root must be `/srv/sportshub/backend/public`, not the repository root or all of `backend/`. The web server needs narrowly scoped read access to public PHP/static files and private PHP source/config; PHP needs private writable session storage. It does not need write access to application source, SQL or the frontend.

Before first use:

1. Create a fresh database and separate migration/runtime accounts. Verify the actual engine/version.
2. Install a private `backend/.env` outside the public root or supply secrets using the host's private process configuration. Avoid overwriting an existing environment during a release.
3. Run the migration using the migration account, then run the API using restricted runtime privileges.
4. Provision the initial private administrator using the [explicit CLI provisioner](AUTHENTICATION.md#private-account-provisioning). Do not seed production.
5. Serve the frontend deployment copy with `API_MODE: 'live'` and `API_BASE_URL: '/api/v1'`.
6. Validate routing, sessions, permissions, real health and workflows on that host before announcing deployment.

## Private production configuration

Set the exact HTTPS application origin in `APP_URL`, use `APP_ENV=production` and `SESSION_SECURE=true`, and use a distinct `SESSION_NAME`. Set the database host/port/name/user/password privately. Keep the configured session TTL and login limit intentional. Do not commit real credentials or print the environment to diagnose a failure.

The application reads process variables before `.env`. Its simple `.env` format is one `NAME=value` per line; values are literal, with no shell execution or quote interpolation. Restrict file permissions to the application service/owner. Keep `.env`, source, schema, tests, logs, session files and backups outside web-accessible aliases.

Use HTTPS with `HttpOnly`, secure and appropriately SameSite-scoped session cookies. Ensure PHP knows the real trusted HTTPS/origin settings behind a proxy; do not trust arbitrary client-supplied forwarding headers. Use private, persistent server session storage suitable for the hosting topology. Several PHP workers share MySQL login-attempt buckets, but several independent application hosts also need a deliberate shared session-storage arrangement.

## Database privileges and release migration

A dedicated migration account needs the schema/table/index privileges required by the reviewed installation SQL and access to migration markers. A runtime account needs only required SELECT/INSERT/UPDATE/DELETE privileges on this application's schema, including `login_attempts` and `activity_logs`; it should not receive global privileges, CREATE USER, DROP or access to another application's data.

One possible database-specific runtime grant is shown below; choose a private password and the correct source host separately:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON sportshub.*
  TO 'sportshub_runtime'@'YOUR_VERIFIED_APPLICATION_HOST';
```

Run `php backend/database/migrate.php` using a private migration environment, then restore the restricted runtime environment for the PHP service. The initial migration is checksum-tracked and non-destructive; it cannot repair arbitrary existing schemas. Future changes need additive migrations, backups and compatibility review. Do not change an applied checksum to suppress a mismatch.

Never run `seed.php` against production. It requires exact development mode and a confirmation flag, but environment isolation remains the operator's responsibility. The public synthetic seed credentials are not production secrets or a default administrator. The private provisioner refuses duplicates and does not reset/promote existing users.

## Apache 2.4 arrangement

Use an HTTPS virtual host whose document root is `backend/public`, enable PHP through the host's supported handler/FPM setup, and enable `mod_rewrite`. The supplied `backend/public/.htaccess` routes API requests to `index.php`; allow its rewrite and Options directives with `AllowOverride FileInfo Options`, or transfer the reviewed equivalent rules into the virtual host and use `AllowOverride None`.

The frontend is outside that root. Expose only its index, CSS and JavaScript through explicit aliases. For example, adapt this routing/access fragment inside an existing reviewed HTTPS virtual host; certificate, PHP-handler, log paths and service-user settings are host-specific:

```apache
DocumentRoot "/srv/sportshub/backend/public"

<Directory "/srv/sportshub/backend/public">
    Options -Indexes
    AllowOverride FileInfo Options
    Require all granted
</Directory>

AliasMatch "^/$" "/srv/sportshub/nsbm-sportshub-frontend/index.html"
Alias "/index.html" "/srv/sportshub/nsbm-sportshub-frontend/index.html"
Alias "/js/" "/srv/sportshub/nsbm-sportshub-frontend/js/"
Alias "/css/" "/srv/sportshub/nsbm-sportshub-frontend/css/"

<Directory "/srv/sportshub/nsbm-sportshub-frontend">
    Options -Indexes
    AllowOverride None
    Require all denied
    <Files "index.html">
        Require all granted
    </Files>
</Directory>
<Directory "/srv/sportshub/nsbm-sportshub-frontend/js">
    Require all granted
</Directory>
<Directory "/srv/sportshub/nsbm-sportshub-frontend/css">
    Require all granted
</Directory>
```

Apply configuration through the host's normal review/reload process and test syntax first. Do not replace a shared server's entire configuration. Confirm `/` serves the frontend, `/js/config.js` serves live configuration with the correct MIME type, and `/api/v1/system/health` executes PHP. Confirm source/config/database/test paths return a denial or 404. Do not add a broad alias for the entire repository to solve a missing asset.

Hash navigation needs no catch-all frontend rewrite: a browser fragment such as `#/reports` never reaches Apache. Keep API routing separate from static content. Directory listing must stay disabled. If future assets are added, expose only the intended static directory after review.

## Nginx or managed PHP hosting

Apply the same boundaries: public root `backend/public`, explicit existing frontend index/JS/CSS static mappings, `/api/v1` routed to the PHP front controller, and source/config/SQL/logs inaccessible. Pass the correct front-controller script filename to the host's PHP-FPM socket; avoid a broad PHP execution rule over writable directories. Nginx does not read `.htaccess`, so its API location must implement the equivalent routing explicitly.

On managed hosting that cannot set aliases/document roots, first confirm it can enforce these boundaries. A whole-repository public upload is not an equivalent deployment. Do not expose `.env` or move private PHP/database files under a static public directory to accommodate a control panel.

## Verify the deployed behavior

Use synthetic acceptance accounts and private operational checks. Confirm the exact HTTPS origin, cookie flags/path, anonymous CSRF bootstrap, login rotation, reload restoration, logout and server expiry. Check student/admin permissions and private histories by making direct API requests as well as using the UI.

Exercise persisted admin CRUD, membership review, tournament registration, standings, booking overlap/review/cancellation, profile preferences, dashboard and reports. Check validation/conflict responses and that API output contains no PHP notices, HTML or private database details. A failed database probe must return 503 with a safe body; simulate service loss only in a disposable/staging setup, not by stopping a shared production database.

Check native-module MIME types and UI/keyboard behavior at representative mobile, tablet and desktop widths. Confirm live failures stay failures rather than showing mock data. Review private logs and backups with access limited to operators; do not collect request bodies/passwords/session tokens as routine diagnostics. Test restoring an authorized private backup in an isolated environment before relying on it.

Record the deployed URL, versions, date and actual results separately. Passing local tests establishes local behavior within their scope; it does not establish TLS, proxy, cookie or availability behavior on an untested host.
