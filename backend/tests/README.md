# Backend integration tests

These tests use actual PHP HTTP requests and MySQL 8.0.16+ with InnoDB. No API responses or database queries are mocked. Run them only on an isolated, disposable schema named exactly `sportshub_backend_test`.

From the repository root, using the prepared local container helper:

```sh
backend/var/local/manage exec -T -e APP_ENV=development -e DB_NAME=sportshub_backend_test app php tests/run.php --confirm-test-reset
backend/var/local/manage exec -T -e APP_ENV=development -e DB_NAME=sportshub_backend_test app php tests/integration/concurrency.php --confirm-test-reset
```

For another configured PHP/MySQL installation, run the same PHP commands from `backend/` and explicitly set `APP_ENV=development`, `DB_NAME=sportshub_backend_test`, and the remaining database connection variables. The configured account must have access to this isolated schema. The concurrency observer also needs `SELECT` on `performance_schema.data_lock_waits` and `performance_schema.data_locks`. Those observation grants are only for the test environment.

`run.php` refuses to run without the confirmation flag, refuses any other environment or database name, and checks `SELECT DATABASE()` immediately before dropping tables. It rebuilds tables only in the selected disposable schema, applies the real migration and synthetic development seed, and leaves its synthetic records for inspection. It starts and stops its own PHP servers on ports 8082 and 8083; it refuses occupied ports. The existing development server and development database are separate. Do not run these suites concurrently against the same test schema.

The HTTP suite checks all 43 methods declared in the existing frontend endpoint inventory. It also checks real database constraints, migration and seed repeatability, safe user provisioning, sessions and cookie rotation, CSRF, authorization and ownership, validation and errors, filtering and pagination, CRUD dependencies, membership and registration capacity, booking overlap and cancellation, match standings, reports, health and a real database outage, session expiry, and persistent login rate limiting. Server diagnostics stay in ignored `backend/var/test-http.log`.

Run the concurrency suite after the main suite. It adds uniquely named synthetic fixtures without resetting tables. For each protected workflow, one PDO process holds the parent row lock while an independent PHP/PDO process attempts the operation. The test observes an actual MySQL lock wait, commits a competing capacity or overlapping reservation change, and verifies the waiting operation returns a conflict without leaving partial rows. This covers membership approval, the final tournament registration slot, and an overlapping booking.
