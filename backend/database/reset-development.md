# Development database reset and safe seeding

`schema.sql` is additive and contains no DROP/TRUNCATE statements. `migrate.php` applies it with a database-scoped MySQL advisory lock and records a checksum in `schema_migrations`. Repeating the migration verifies the checksum and preserves data. MySQL DDL auto-commits: a failed first import may leave already-created tables, so correct the underlying error and rerun the unchanged migration. `CREATE TABLE IF NOT EXISTS` completes missing tables; it does not repair arbitrary pre-existing table definitions. Use a fresh, dedicated database for first installation. Never edit an applied migration to alter an existing deployment.

From `backend/`, after securely configuring `.env` and creating a dedicated empty database:

```bash
php database/migrate.php
php database/seed.php --confirm-development
```

The seed additionally requires the exact `APP_ENV=development` value. Production, staging, missing flag and non-CLI requests are refused before writes. It creates only synthetic example identities and sports records, hashes public development passwords using Argon2id when available (otherwise PHP's supported default), and commits all records plus a seed marker in one transaction. A seed advisory lock prevents competing CLI seed executions. Running the seed again preserves existing records, local edits and passwords. An existing unmarked dataset is refused rather than mixed with public demonstration accounts.

Dates are relative to the first seed execution and remain unchanged on repeat runs. Long-lived seed tournaments will eventually reach their deadlines; use the ordinary admin workflow to adjust future dates. Seeding never automatically runs during migration, web startup or production deployment.

The known development logins are documented in [the backend setup guide](../docs/README.md). They must never be present in a production database. Resetting does not rotate credentials for any valued existing installation.

## Start over only when the data is disposable

Prefer creating a **new empty development database** and changing the private `DB_NAME` to that name, followed by migration and explicit seed. This leaves the earlier dataset recoverable. Back up valued records first; do not drop a database just because a migration/seed reports an error. Do not delete Docker volumes, reset shared schemas or run destructive tests against the application database.

The isolated test workflow uses the exact disposable test schema documented in `backend/docs/TEST_RESULTS.md`. Its destructive reset flag does not authorize resetting your normal development or production database. Keep test and application database configuration separate.
