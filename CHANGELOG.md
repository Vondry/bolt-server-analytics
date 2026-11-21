# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-11-21

### Added

- Rollups without cron: a visit starts one at most every
  `aggregation.visit_interval_minutes` (60; 0 = never), once its page has been
  sent (PHP-FPM, LiteSpeed, FrankenPHP). Not with `trigger: cron`.
- The rollup URL for a hosting whose cron can only call a URL (WEDOS):
  `aggregation.rollup_url` (off by default) with `aggregation.rollup_token`, a
  secret of 32+ characters. Without the token the request goes on as if the
  URL were not there.
- `privacy.raw_max_days` (0 = off): visits that wait longer than that in the
  spool for the rollup are deleted unread, and that is logged. A spool line
  describes one request.
- Bot detection: requests signed by their agent (`Signature-Agent`,
  `Signature-Input`; Web Bot Auth), by operator where it is known; Chromium whose
  Client Hints name `HeadlessChrome`, whatever its User-Agent; Firefox 90+ and
  Safari 16.4+ User-Agents sent without fetch metadata and languages.
- A day with more than 20 000 rows in a table of values that visitors send
  (pages, sources, page flow, search terms, aliases, broken links) keeps only
  the values seen `report_threshold_k` times or more: a flood of made-up values
  cannot fill the database, nor push out the site's own. Daily totals stay
  exact.
- `SECURITY.md`: how to report a vulnerability.

### Changed

- `utm_campaign` and `utm_source` are stored without e-mail addresses and phone
  numbers (redacted), and with ids (5+ digits, UUIDs, hashes) as `:id`, as in
  paths. Newsletter tools put them there per recipient.
- The User-Agent is classified in each process only; `collector.ua_cache` no
  longer does anything (it is still accepted). APCu filled up with every
  made-up User-Agent a client sent, for a day.

### Fixed

- The Analytics menu item, page and dashboard card were gone, with nothing to
  say why, whenever the admin session was restored from the "remember me"
  cookie: a full login was required. Now a login is enough, as for the rest
  of Bolt's backend; the roles of `access.view` and `access.delete` still
  decide.
- Phones of the brand Cubot were counted as bots.
- A spool line cut short (a full disk) no longer spoils the next one.

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

[Unreleased]: https://github.com/Vondry/bolt-server-analytics/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/Vondry/bolt-server-analytics/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Vondry/bolt-server-analytics/releases/tag/v1.0.0
