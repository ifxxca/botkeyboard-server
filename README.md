# AWB Attention Server — M2

Laravel 13 / PHP 8.4+ / MySQL 8 central attention API and authenticated admin surface. The client page shows each registered agent as online, offline, never connected, or disabled from a separate heartbeat sent by Windows agent 0.4.0.

See [M2 setup and architecture](../docs/M2-CENTRAL-SERVER.md), [Excel/CSV import](../docs/EXCEL-IMPORT.md), [API contract](../docs/API-CONTRACT.md), and [test results](../docs/TEST-RESULTS.md).

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
# Configure MySQL credentials and an isolated database in .env.
php artisan migrate --force
php artisan app:create-admin admin@example.com
php artisan serve --host=127.0.0.1
php artisan test --compact
```

Build the shadcn-style Blade/Tailwind admin assets with `npm install` and `npm run build`. For live CSS/Blade development, run exactly one `npm run dev` process; Laravel will then load assets from `http://127.0.0.1:5173`. Stop Vite before testing the production build because the presence of `public/hot` makes `@vite` prefer the dev server over `public/build`.

Client presence uses `POST /api/v1/client/heartbeat`; the default online window is 90 seconds and can be changed with `ATTENTION_CLIENT_ONLINE_SECONDS`. Admin timestamps default to `Asia/Jakarta`; override `ATTENTION_DISPLAY_TIMEZONE` when needed.

The test suite uses only an isolated MySQL database with a name ending in `_test`. The Excel reader requires the PHP `zip` extension. The local server and example `.env` are not a production deployment configuration.

For production, start from `.env.production.example`, deploy behind HTTPS with the document root set to `public`, and run `php artisan app:deployment-check` after migration and after the first approved import. The command prints no secrets and exits nonzero when a blocking prerequisite is missing. See [installation](../docs/INSTALLATION.md) and the [operations runbook](../docs/OPERATIONS-RUNBOOK.md).
