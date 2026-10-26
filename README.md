# omniticket/eventbrite

[omnisense/omniticket](https://github.com/glitchr-omnisense/omniticket)'s Eventbrite, under any PHP
host: an organization's events with their ticket classes, price range and availability, the
attendees of an event (incrementally), and a ticket's barcode checked among them. Reading only.

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
