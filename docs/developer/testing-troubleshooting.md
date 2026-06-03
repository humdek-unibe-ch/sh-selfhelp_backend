# Testing Troubleshooting

Common failures when running the SelfHelp backend test suite, with the diagnostic to run and the fix. See `docs/developer/15-testing-guidelines.md` for the overall setup and `AGENTS.md` → Testing Rules for policy.

Each entry: **symptom → diagnostic → likely cause → fix**.

## MySQL container not ready

- **Symptom**: `SQLSTATE[HY000] [2002] Connection refused` / `mysql: connection refused` during `composer test:reset-db`.
- **Diagnostic**: `docker compose -f docker-compose.test.yml ps` (inspect the healthcheck column).
- **Cause**: healthcheck still pending, host port collision, or MySQL crashed on boot.
- **Fix**: `docker compose -f docker-compose.test.yml down -v && docker compose -f docker-compose.test.yml up -d --wait`.

## Redis connection refused

- **Symptom**: `redis: connection refused` / cache pool errors.
- **Diagnostic**: `docker compose -f docker-compose.test.yml ps`.
- **Cause**: Redis container not healthy yet or wrong `REDIS_URL` port.
- **Fix**: bring the stack up with `--wait` as above; confirm `REDIS_URL` matches the published port in `docker-compose.test.yml`.

## Mercure JWT mismatch

- **Symptom**: Mercure rejects a publish with HTTP 401 during a test that exercises realtime.
- **Diagnostic**: compare `MERCURE_JWT_SECRET` in your `.env.test` with the value baked into the dev hub in `docker-compose.test.yml`.
- **Cause**: the publisher secret does not match the hub secret.
- **Fix**: align `.env.test` with `docker-compose.test.yml`. In unit/integration/golden tests prefer the in-memory `MercureTestRecorder` (aliased in `config/services_test.yaml`) so no real hub is needed.

## JWT keys missing

- **Symptom**: `JWTNotFoundException` / login returns 500 in the smoke test.
- **Diagnostic**: check `config/jwt/private.pem` and `config/jwt/public.pem` exist.
- **Cause**: the JWT keypair is gitignored and was never generated in this checkout.
- **Fix**: `php bin/console lexik:jwt:generate-keypair --skip-if-exists`.

## Fixture missing

- **Symptom**: `QaWebTestCase` aborts with "QA fixture not loaded" / "QA baseline not found".
- **Diagnostic**: `composer test:smoke` (it prints the detected fixture state).
- **Cause**: the test DB was created/migrated but not seeded.
- **Fix**: `composer test:reset-db`.

## Database not reset / fixture version mismatch

- **Symptom**: tests pass once then fail on the next run, or the smoke test reports a `QA_FIXTURE_VERSION` mismatch.
- **Diagnostic**: re-run `composer test:smoke`; confirm DAMA is enabled in `config/packages/test/doctrine.yaml`.
- **Cause**: DAMA disabled (writes leaked), a stale seed, or dirty rows from an interrupted run.
- **Fix**: `composer test:reset-db`; verify the DAMA extension is present in `phpunit.dist.xml` and DAMA is enabled in the test Doctrine config.

## Permission matrix failure

- **Symptom**: `qa.user` got 200 where 403 was expected (or vice versa).
- **Diagnostic**: inspect the persona's roles/permissions through the admin API or the user detail endpoint as `qa.admin`.
- **Cause**: a production permission change without a matching test update, or a test using a role/group that does not exist.
- **Fix**: align the production permission and the test expectation. Do **not** weaken the permission to make the test pass (canonical rule 23) — fix whichever side is actually wrong.

## Schema-parity failure (shared repo)

- **Symptom**: `scripts/check-schema-parity.mjs` fails in the shared package CI.
- **Diagnostic**: read the diff it prints between backend JSON Schemas and shared TS types.
- **Cause**: a backend response schema was added/changed without updating the shared TS type.
- **Fix**: update the shared TS type and the frontend/mobile/plugin consumer in the **same PR** (canonical rule 28).

## `/forms/submit` 404 on a fresh DB

- **Symptom**: a form-submission test gets 404 on `/cms-api/v1/forms/submit` right after a reset.
- **Diagnostic**: list API routes (`php bin/console debug:router | grep forms`) or query `api_routes`.
- **Cause**: the form route seed did not run (rare).
- **Fix**: re-run `composer test:reset-db`; if it persists, apply the dedicated form-route migration (Slice 4).

## New DB-backed API route 404s locally after adding its migration

- **Symptom**: a route you just seeded via a migration (e.g. `/cms-api/v1/health`) 404s in a test or request, even though `php bin/console debug:router | grep <route>` lists it.
- **Diagnostic**: confirm the row exists (`php bin/console dbal:run-sql "SELECT * FROM api_routes WHERE route_name='<name>'" --env=test`) — if it does but requests still 404, it is a stale route cache.
- **Cause**: `ApiRouteLoader` caches the route collection (Redis, all envs except `dev`) and Symfony compiles the matcher into `var/cache/<env>`. After a migration adds a route, both can be stale. Clearing in the wrong order re-warms the compiled matcher from the still-stale cached collection.
- **Fix**: clear the API-routes cache **first**, then the Symfony cache: `php bin/console cache:clear-api-routes --env=test && php bin/console cache:clear --env=test`. (CI never hits this — a fresh checkout compiles the router after migrations + an empty cache.)

## "Production logic changed to make a test pass" — PR rejected

- **Symptom**: review blocks the PR for relaxing a validation/permission/security/error-handling check.
- **Cause**: a test was made green by weakening production behaviour.
- **Fix**: revert that change. Decide which side was wrong: if the **test expectation** was wrong, fix the test; if **production** was wrong, fix the production code and explain the bug in the PR (this is exactly how Slice 1B surfaced and fixed the `DateTimeImmutable` scheduled-job bug).
