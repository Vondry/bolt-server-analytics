# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-11-15

### Added

- Collection after the response (`kernel.terminate`) from the HTTP request only:
  page views, 404s with where the link was, bots by purpose and operator, domain
  aliases and QR-code markers. One line appended to an hourly spool file per hit,
  no database connection, no cookies, identifiers or IP addresses.
- Rollup of the spool into daily aggregate tables in a database of the
  statistics' own: a SQLite file by default, or MySQL / MariaDB / PostgreSQL.
  Runs within a time budget when an administrator opens the dashboard, or from
  cron (`request-analytics:rollup`); never counts a line twice, also with
  several web servers.
- Dashboard card and an Analytics page: headline, trend with comparison,
  devices, browsers and operating systems, hours by weekday, pages with their
  languages and page flow, sources, campaigns, aliases, broken links, site
  search and bots, for 7 days up to 3 years.
- Insights: findings worth acting on, each with its likely cause and a next
  step; a warning on the menu item while several ask for a fix.
- Deleting the statistics of the last days, or all of them.
- `request-analytics:install` to create or update the tables, with `--vacuum`
  to compact the SQLite file.
- Access by roles (`access.view`, `access.delete`), CSRF and same-origin checks
  on every endpoint, security headers on every response.
- Privacy: GPC and DNT honoured, values below `report_threshold_k` only as
  counts, `retention_months` for the detailed aggregates.
- `collector.max_spool_mb`, so the spool never fills the disk.
- Translations into every language of Bolt's backend, plus Portuguese and
  Romanian.

[Unreleased]: https://github.com/Vondry/bolt-server-analytics/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Vondry/bolt-server-analytics/releases/tag/v1.0.0
