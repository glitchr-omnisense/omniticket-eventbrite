# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omniticket` `^1.0` with no stability flag, so `composer require omniticket/eventbrite` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

### Changed
- Each page of a listing counted: `events()`, `attendees()` and an event's ticket classes fetch the first page as before (the registry's guard
  counts the operation) and each further page through the provider's guard (`Config::guard()`,
  from the factory, the constructor's last argument `pages`), under the same operation, checked before it is requested and
  counted after in the registry's limiter: a limit reached stops the listing before the next
  page. Needs omnisense/contracts 16c8da1 or later (`Config::guard()`; 1568f2c, what the sites'
  vendors hold, has it). Tests/PolicyTest: a page over a site's limit never requested.
