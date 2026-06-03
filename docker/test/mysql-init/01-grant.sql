-- SPDX-FileCopyrightText: 2026 Humdek, University of Bern
-- SPDX-License-Identifier: MPL-2.0
--
-- Runs once on first MySQL container start (docker-entrypoint-initdb.d).
--
-- The MySQL image grants the `qa` user privileges only on the exact
-- MYSQL_DATABASE (`selfhelp`). The TEST suite, however, talks to the
-- `_test`-suffixed database (`selfhelp_test`, appended by the when@test
-- Doctrine config) and `app:test:reset-db` must DROP/CREATE it. A
-- wildcard grant on `selfhelp%` covers `selfhelp`, `selfhelp_test` and any
-- ParaTest `selfhelp_test<token>` shards, including CREATE/DROP DATABASE.
GRANT ALL PRIVILEGES ON `selfhelp%`.* TO 'qa'@'%';
FLUSH PRIVILEGES;
