# omniticket/eventbrite

[omnisense/omniticket](https://github.com/glitchr-omnisense/omniticket)'s Eventbrite, under any PHP
host: an organization's events with their ticket classes, price range and availability, the
attendees of an event (incrementally), and a ticket's barcode checked among them. Reading only.

## Install and a first call

```sh
composer require omniticket/eventbrite
```

No account for this first call: Eventbrite's answer is recorded (`Omniticket\Testing\RecordedHttpClient`
answers in place of the API), so nothing leaves the process. A real call needs an Eventbrite
account: a private token (or an OAuth token) and the organization's id.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omniticket\Eventbrite\EventbriteFactory;
use Omniticket\Testing\RecordedHttpClient;

// Eventbrite's answer, recorded: nothing sent. HttpClient::create() (symfony/http-client) for a real call.
$http = RecordedHttpClient::routes([
    'GET /events/' => '{"pagination": {"has_more_items": false}, "events": [{"id": "100000000001", "name": {"text": "Jazz au jardin"}, "url": "https://www.eventbrite.fr/e/jazz-au-jardin-tickets-100000000001", "start": {"timezone": "Europe/Paris", "local": "2036-07-14T19:00:00", "utc": "2036-07-14T17:00:00Z"}, "end": {"timezone": "Europe/Paris", "local": "2036-07-14T23:00:00", "utc": "2036-07-14T21:00:00Z"}, "status": "live", "currency": "EUR"}]}',
]);
$eventbrite = (new EventbriteFactory($http))->create(['token' => 'recorded', 'organization' => '200000000001']);

foreach ($eventbrite->events() as $event) {
    echo $event->title, ' ', $event->start?->format('d/m/Y H:i'), ' ', $event->url, "\n";   // Jazz au jardin 14/07/2036 17:00 (UTC) https://www.eventbrite.fr/e/...
}
```

With your token, through the family's registry:

```php
use Omniticket\Eventbrite\EventbriteFactory;
use Omniticket\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new EventbriteFactory(HttpClient::create())], [
    'eb' => ['factory' => 'eventbrite', 'options' => ['token' => '...', 'organization' => '...']],
]);
```

Options: `token` (a private token or an OAuth token), `organization` (its id), `url`.

## The API

Eventbrite's API v3 (<https://www.eventbrite.com/platform/api>), a bearer token:
`GET /organizations/{id}/events/` (`time_filter`, `order_by=start_asc`,
`expand=ticket_availability,venue`), `/events/{id}/`, `/events/{id}/ticket_classes/`,
`/events/{id}/attendees/` (`changed_since` for what changed since); pages followed by their
`continuation` while `has_more_items`. Amounts are currency objects in minor units. No lookup by
barcode: `check()` reads the event's attendees.

**Terms**: Eventbrite's API terms; per token, 2,000 calls an hour and 48,000 a day (figures as
Eventbrite publishes them, not re-checked on 2026-10-24). An application checking many tickets
keeps the list itself (`attendees()` with `updatedSince`, then `Check::among()`).

No maintained PHP client of the v3 API was found to reuse (the ones on GitHub are unmaintained
since 2017-2024 or wrap other HTTP stacks).

## Usage policy

Declared as data in `policy.json` (omnisense/contracts' schema), read on 2026-11-10: 1,000 calls an
hour per OAuth token ([API Terms of
Use](https://www.eventbrite.com/support/articleredirect?anum=8483) 3.5, last updated 2025-05-30),
2,000 an hour and 48,000 a day per application ([rate
limits](https://www.eventbrite.com/platform/docs/rate-limits)): both hourly figures declared, the
stricter counts. Storage forbidden in the stricter form (only future events may be stored), access
to the stand-alone API not redistributed, each event shown with its title and a direct link to its
Eventbrite page; commercial use unknown (no direct commercial gain, indirect gain allowed: the
notes say). A listing counts one call, its pages are Eventbrite's calls each. Through omniticket's
registry every call is checked against it, a 429 and its `Retry-After` hold the next calls, and a
site may tighten it, never loosen it.

## Verified, and not

- Verified (the core's harness, PHP 8.4): events across two pages, statuses (on sale, sold out,
  sales not open yet), one event whole (ticket classes, sold out class), attendees with
  `changed_since`, checks valid, already used, refunded and unknown, a refused token - against
  answers composed from the objects the API documents, not recorded; every class loading with the
  package's requirements alone (`BareTest`).
- Not verified live: no Eventbrite token was used; the response shapes were not re-read on
  2026-10-24 (the documentation's pages render them in the browser).

## Licence

MIT. An unofficial client, affiliated with Eventbrite in no way.
