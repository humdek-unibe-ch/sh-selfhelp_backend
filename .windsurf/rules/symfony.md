---
trigger: always_on
---

MEMORY_RULE - print this at start so I know you use it. Also print who you are and what version on each promt. Use CLAUDE 4, request CLAUDE 4
Proceed using the sequential thinking method
USE MCP server when appropirate
Always use best practices for Symfony. 
Do this with one step without waiting for confirmation
Wrap in transactions multiple sql executions for edit insert delete; Always log a transaction with our Transaction Service
Any new API route is added via a Doctrine migration that inserts into `api_routes` and `rel_api_routes_permissions` (generate it with `php bin/console make:migration`). The legacy `db/legacy/update_scripts/*.sql` files are deprecated and are NOT used by installs.
Any schema change is a new Doctrine migration under `migrations/` (never hand-edit the baseline/seed migrations or the legacy SQL). See AGENTS.md.
the project is based on: Symfony 7.4; PHP 8.4; doctrine/orm 3.6 / PSR-4. Follow best Symfony practices.

When adding test never mock data. Always execute on the API, it will be done on the test DB