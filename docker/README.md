# SportHubv2 with Docker

This optional development setup runs Apache/PHP 8.2 and MySQL 8.4. The app
container is named **SportHubv2**, and its database is **SportHubv2-db**. Docker
requires lowercase Compose project names, so their project is `sporthubv2`.
Native PHP/XAMPP hosting remains available without Docker.

Install/start Docker Desktop (including Compose v2), then run these commands
from the `NewProject` repository root, where `compose.yaml` is located:

```sh
sh docker/setup-env.sh
docker compose --env-file .env.docker up -d --build --wait
```

Open **http://localhost:8080**. The API is at
**http://localhost:8080/api/v1/**. Both use one origin; no separate frontend
server or change to the native frontend configuration is needed.

The helper generates private database passwords and a random JWT signing secret
in the ignored `.env.docker`. Running it again preserves existing nonempty
values. It also fills a missing JWT secret if an older Docker environment file
contains only database passwords. OpenSSL is available by default on macOS and
most Linux installations. Alternatively, copy `.env.docker.example` to
`.env.docker` and set three independently generated random values manually.
Keep this file across updates; database passwords must match the persisted
database and JWT secret changes invalidate issued tokens.

The first startup imports `backend/database/schema.sql` and `seed.sql`
automatically into the new `sporthubv2_mysql_data` volume. No manual seed
command is needed. Subsequent starts/rebuilds preserve database records and do
not rerun the initialization scripts. This uses a new volume, separate from
previous Docker versions. MySQL is accessible only inside the Compose network.

## DEVELOPMENT ONLY accounts

| Role | Email | Password |
| --- | --- | --- |
| Student | `student@nsbm.ac.lk` | `password123` |
| Club manager | `clubmanager@nsbm.ac.lk` | `password123` |
| Sports administrator | `admin@nsbm.ac.lk` | `admin123` |
| Super administrator | `superadmin@nsbm.ac.lk` | `admin123` |

## Updates and common commands

After pulling newer source on your current branch, rebuild with the same private
configuration:

```sh
docker compose --env-file .env.docker up -d --build --wait
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs -f app
```

Stop the containers while retaining the database:

```sh
docker compose --env-file .env.docker down
```

`down -v` deletes this version's database volume; use it only when you intend to
erase that data and initialize a fresh development database.

If port 8080 is occupied, add `APP_PORT=8090` to `.env.docker`, rerun `up`, and
open http://localhost:8090. The frontend API URL, backend URL, and allowed origins
follow the configured port automatically.

If Compose reports “no configuration file provided,” change into the repository
root containing `compose.yaml`. If it reports a missing `.env.docker` or
`JWT_SECRET`, run `sh docker/setup-env.sh` there before starting the containers.

## Optional API verification

With Python 3 installed as a QA client:

```sh
python3 backend/tests/integration.py --base-url http://localhost:8080/api/v1 --origin http://localhost:8080
```

This writes and cleans up temporary test resources in the development database.
The application itself requires only Docker Desktop for this setup.

## Verification

Verified on 6 November 2026 using this Compose setup: both services became
healthy, 703 real HTTP/API assertions and 17 browser checkpoints passed, and
`down` followed by `up -d --build --wait` preserved a temporary database record
and its issued JWT. The temporary record was removed afterward. Apache's
configuration and private-file exclusions also passed inspection. The
environment helper preserves existing credentials on repeated runs and fills
missing or quoted-empty entries.
