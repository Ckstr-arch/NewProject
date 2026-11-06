# PHP/MySQL backend integration

The current implementation uses the user's PHP/MySQL build specification:
plain PHP 8.2+, PDO, MySQL 8+, bearer JWT authentication, snake_case payloads,
and `data`/`error` JSON envelopes.

Read [PHP_BACKEND_INTEGRATION.md](PHP_BACKEND_INTEGRATION.md) for frontend setup
and [`backend/README.md`](../../backend/README.md) for complete executable setup,
Apache/XAMPP hosting, schema/seed imports, API examples, and security behavior.

The earlier standalone demo's cookie/CSRF contract is superseded for live mode.
The demo remains available only when explicitly selected with `API_MODE: 'mock'`.
