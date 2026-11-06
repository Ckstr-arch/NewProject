# NSBM SportsHub

NSBM SportsHub combines a Vanilla HTML/CSS/ES6 frontend with a plain PHP 8.2+ REST API and MySQL 8. The browser sends JSON over HTTP; only PHP accesses the database.

- [Backend setup, API reference, and development credentials](backend/README.md)
- [Manual integration checklist](backend/docs/API_TEST_CHECKLIST.md)
- [Automated API tests](backend/tests/README.md)
- [Frontend documentation](nsbm-sportshub-frontend/docs/README.md)
- [SportHubv2 Docker setup](docker/README.md)

## Run SportHubv2 with Docker

Start Docker Desktop, then run from this repository root:

```sh
sh docker/setup-env.sh
docker compose --env-file .env.docker up -d --build --wait
```

Open **http://localhost:8080**. The app container is named **SportHubv2**. MySQL
is initialized and seeded automatically on first startup; a named volume
preserves records across restarts and rebuilds. See [the Docker guide](docker/README.md)
for updates, ports, and troubleshooting.

## Run locally

1. Create the `nsbm_sportshub` MySQL database with `utf8mb4_unicode_ci` collation.
2. Import `backend/database/schema.sql`, then `backend/database/seed.sql`, using phpMyAdmin or the MySQL client.
3. Copy `backend/.env.example` to `backend/.env`. Set your database credentials and a random `JWT_SECRET` of at least 32 bytes. Never commit this file.
4. Start the API:

   ```sh
   cd backend
   php -S localhost:8000 router.php
   ```

5. In another terminal, serve the frontend:

   ```sh
   cd nsbm-sportshub-frontend
   php -S localhost:5500
   ```

6. Open `http://localhost:5500`. Live mode connects to `http://localhost:8000/api/v1` through `js/config.js`.

PHP's built-in server is for local development. Apache/XAMPP and ordinary PHP/MySQL hosting are supported; see the backend guide for subdirectory URLs and configuration. Node.js, npm, Composer, and Docker are not required.

## Development accounts

**DEVELOPMENT ONLY.** These accounts are created by `seed.sql`. Do not import demonstration users into a production database.

| Role | Email | Password |
| --- | --- | --- |
| Student | `student@nsbm.ac.lk` | `password123` |
| Club manager | `clubmanager@nsbm.ac.lk` | `password123` |
| Sports administrator | `admin@nsbm.ac.lk` | `admin123` |
| Super administrator | `superadmin@nsbm.ac.lk` | `admin123` |

Live mode provides dashboard metrics and clubs, schedules, tournaments, and facility bookings. The original frontend's other modules remain available only in explicit demo mode; their demo records are not MySQL data.
