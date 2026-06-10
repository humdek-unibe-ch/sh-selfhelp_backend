# Backend Operations

Audience: Operators and deployers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-09.
Source of truth: Runtime configuration, environment variables, and services in this repository.

Operational configuration and runbooks for running the backend.

- [platform-and-plugin-ecosystem.md](platform-and-plugin-ecosystem.md) - The big map: one unified registry, two installers (Manager owns the Docker core; the CMS owns plugins), and how to install/update/maintain from the Manager and from within the CMS.
- [file-upload-configuration.md](file-upload-configuration.md) - File upload limits, allowed types, and storage configuration.
- [docker-release-pipeline.md](docker-release-pipeline.md) - Production backend/worker/scheduler images: build, SBOM, license policy, scan, signing, and publish.

For deployment and release flow, see [../developer/16-deployment-process.md](../developer/16-deployment-process.md). For local debugging with a production database copy, see [../developer/22-local-debugging-with-production-db.md](../developer/22-local-debugging-with-production-db.md).
