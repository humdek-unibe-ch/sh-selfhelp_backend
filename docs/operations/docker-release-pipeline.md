# Backend Docker Release Pipeline

Audience: Operators, release engineers.
Status: active.
Applies to: SelfHelp2 Symfony backend production images.
Last verified: 2026-06-11.
Source of truth: `docker/Dockerfile`, `docker/.env.image-defaults`, `.github/workflows/docker-release.yml`, `docker/license-policy.json`, `scripts/check-license-policy.php`.

The backend ships three production Docker images that the unified registry's core
release record points at. They are built from a single multi-stage Dockerfile and
published, scanned, SBOM'd, license-checked, and signed by one CI workflow.

## Images

| Target | Published name | Role | Default command |
| --- | --- | --- | --- |
| `backend` | `ghcr.io/<owner>/selfhelp-backend` | Web app (FrankenPHP, port `8080`) | FrankenPHP serves `public/` |
| `worker` | `ghcr.io/<owner>/selfhelp-worker` | Messenger consumer | `messenger:consume plugin_ops` |
| `scheduler` | `ghcr.io/<owner>/selfhelp-scheduler` | Due-jobs loop | `app:scheduled-jobs:execute-due` |

All three share the same `base` (FrankenPHP + `intl`, `pdo_mysql`, `zip`, `opcache`)
and `vendor` (`composer install --no-dev --classmap-authoritative`) stages, so the
source + dependencies are identical across them.

The web image is served by FrankenPHP (Caddy + PHP). Operators never write nginx or
Caddy config; the SelfHelp Manager's Traefik proxy terminates TLS and routes to port
`8080`. The image `HEALTHCHECK` polls the public readiness probe
`GET /cms-api/v1/health`.

## Build locally

```bash
docker build --target backend   -f docker/Dockerfile -t selfhelp-backend   .
docker build --target worker    -f docker/Dockerfile -t selfhelp-worker    .
docker build --target scheduler -f docker/Dockerfile -t selfhelp-scheduler .
```

Runtime configuration (`APP_SECRET`, `DATABASE_URL`, `REDIS_URL`, `MERCURE_*`,
JWT keys, `MESSENGER_PLUGIN_OPS_DSN`, ...) is provided at container start by the
Manager-generated compose stack, never baked into the image. The `.dockerignore`
keeps host `vendor/`, caches, tests, every local `.env*` file, and JWT keys out of
the build context, so a local build is byte-identical to a CI build and can never
leak developer secrets into an image.

## Baked dotenv defaults (`docker/.env.image-defaults`)

Symfony's runtime boots a dotenv file on every request and console command, so the
image must always contain `/app/.env` — without it the container fatals with a
`PathException` before serving anything (every request 500s, every `bin/console`
invocation dies). Because the repository git-ignores `.env`, the Dockerfile bakes
the committed, secret-free `docker/.env.image-defaults` into the image as `/app/.env`.

That file carries only safe defaults for env vars the backend cannot run without
and that are not secrets: `JWT_TOKEN_TTL`, `JWT_REFRESH_TOKEN_TTL`, the JWT key
*paths*, a localhost-only `CORS_ALLOW_ORIGIN`, a Mailpit `MAILER_DSN` (production
overrides it with a real SMTP DSN), and the in-network `MERCURE_URL`
(`http://mercure/.well-known/mercure`) - the hub service must instantiate with a
string URL (`new Hub(null)` is a TypeError that 500s every request), while actual
publishes are best-effort and only log on failure. Real container env vars always take precedence, so the Manager's
instance `.env` + `secrets/secrets.env` stay authoritative. Never add a secret or
an instance-specific value to `docker/.env.image-defaults`.

## Release workflow

`.github/workflows/docker-release.yml` runs on `v*` tags (or manual dispatch with a
`version` input) and implements the plan's core release pipeline:

1. `license` job - installs prod dependencies, runs `composer licenses --no-dev
   --format=json`, and enforces `docker/license-policy.json` via
   `scripts/check-license-policy.php`. Blocked/unknown licenses fail the build
   unless `vars.ALLOW_LICENSE_OVERRIDE=1` (explicit reviewer/legal approval).
2. `images` job (matrix over the three targets) - first builds the image locally
   and runs the **boot smoke** (`docker run --rm <image> php bin/console about`):
   the kernel must boot with no host env file or external service available. A
   broken image (for example missing `/app/.env`) is rejected before anything is
   pushed. Then builds + pushes to GHCR, and for each image: generates an SPDX
   SBOM (`anchore/sbom-action`), scans it (`aquasecurity/trivy-action`, reported
   as SARIF), and signs the pushed digest with cosign. Signing uses
   `secrets.COSIGN_PRIVATE_KEY` when present, otherwise keyless via GitHub OIDC
   (`id-token: write`).
3. Each image's digest is written to the job summary.

## License policy

`docker/license-policy.json` encodes the allowed / review-required / blocked license
sets from the plan ("License Compliance For Distributed Images"). The lists must be
confirmed by the project owner / legal responsible before the first public release.
The gate treats a package as compliant if any of its (OR-ed) licenses is allowed,
warns on review-required licenses, and fails on blocked or undeclared licenses.

## After a release

The core release JSON in the unified registry
(`releases/core/selfhelp-core-<version>.json`) pins each image by `image` +
`digest`. Copy the digests from the workflow job summary into that record and
re-sign it, so installs pull exactly the audited, signed images.
