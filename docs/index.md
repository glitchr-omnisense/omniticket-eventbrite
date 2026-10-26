# omniticket/eventbrite

Eventbrite for [omnisense/omniticket](https://github.com/glitchr-omnisense/omniticket): an
organization's events, their ticket classes, their attendees, and a ticket code checked - through
Eventbrite's API v3 (`https://www.eventbriteapi.com/v3`, documented at
`https://www.eventbrite.com/platform/api`) with a bearer token. Reading only. No maintained PHP
client of the v3 API was found to reuse.

| Operation | Eventbrite API v3 |
|---|---|
| `events()` | `GET /organizations/{id}/events/` (`expand=ticket_availability,venue`, `order_by=start_asc`, `time_filter`) |
| `event($id)` | `GET /events/{id}/` (the same expansion), `GET /events/{id}/ticket_classes/` |
| `attendees($id, $since)` | `GET /events/{id}/attendees/` (`changed_since` with `$since`) |
| `check($id, $code)` | the attendees, read whole |

Every list follows `pagination.continuation` while `has_more_items`.

## Installation

```sh
composer require omniticket/eventbrite symfony/http-client
```

Requires PHP 8.2, `omnisense/omniticket` (1.x) and `symfony/http-client-contracts` (^3.0).

## Credentials

A **private token** (Eventbrite's account settings, "Developer links", "API keys") or an OAuth
token, and the **organization's id** (`GET /users/me/organizations/` gives it).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `token` | string | - | yes | sent as a bearer |
| `organization` | string | - | yes | whose events are listed |
| `url` | string | `https://www.eventbriteapi.com/v3` | no | |

## Capabilities

| Contract | Supported |
|---|---|
| `EventCatalogInterface` | yes |
| `AttendeeListInterface` | yes, incremental (`changed_since`) |
| `TicketCheckInterface` | yes, among the attendees |

`capabilities()`: catalog, attendees, check and incremental true; no widget; terms
`https://www.eventbrite.com/platform/api`; limits: per token 2,000 calls an hour, 48,000 a day
(figures as Eventbrite publishes them, not re-checked on 2026-10-24).

## Behaviour

**Events.** `time_filter` is `current_future`, or `all` with a query's `past`. Each event: `id`,
`name.text`, `start.utc`, `end.utc`, `url`, the venue's name and city, `capacity`, the price range
from `ticket_availability.minimum_ticket_price` and `maximum_ticket_price` (currency objects in
minor units). Status: `draft` draft; `canceled` cancelled; `ended`, `completed` ended; `live`,
`started` - sold out when `is_sold_out`, upcoming before `start_sales_date`, sales closed when
`has_available_tickets` is false, else on sale; anything else unknown. A sold-out event's
availability is 0 of its capacity.

**One event.** The event, then its ticket classes as rates: `id`, `name`, the cost (0 in its
currency, USD when none, for a free class), availability (sold out when `on_sale_status` is
`SOLD_OUT`, else from `quantity_total` and `quantity_sold`), `sales_start`, `sales_end`.

**Attendees.** Each: `id`, the first barcode as the code, the profile's first and last names and
e-mail, `ticket_class_name` as the rate, `order_id`. Status: refunded (the flag, or the barcode's
status); cancelled (the flag, or `Not Attending`); checked in (the flag, or the barcode `used`;
the barcode's `changed` as when); `Unpaid` pending; else valid. `$since` is sent as
`changed_since` in UTC.

**Check.** Eventbrite has no lookup by barcode: the event's attendees are read, every page, and
the code looked for among them. An application checking many tickets keeps the list itself.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omniticket\Eventbrite\EventbriteFactory;
use Omniticket\Model\EventQuery;
use Omniticket\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new EventbriteFactory(HttpClient::create(['timeout' => 15]))], [
    'eb' => ['factory' => 'eventbrite', 'options' => ['token' => getenv('EVENTBRITE_TOKEN'), 'organization' => getenv('EVENTBRITE_ORGANIZATION')]],
]);

foreach ($registry->catalog('eb')->events(new EventQuery(limit: 10)) as $event) {
    printf("%s %s from %s\n", $event->start?->format('Y-m-d'), $event->title, $event->priceFrom?->major() ?? '?');
}
```

Not run live (no token). A 429 was mapped on a recorded answer on 2026-10-26: `Eventbrite
answered 429 for event "1": Hourly rate limit has been reached for this token.`

## Errors

- 401, 403: `InvalidKeyException`; 404: `NotFoundException`; another status of 400 or more, or an
  answer that is not JSON: `ProviderException` with Eventbrite's `error_description`, the status
  as its code.
- A network failure: `UnreachableException`.

## Verified, and not

`Tests/EventbriteProviderTest.php`, against answers composed from the objects Eventbrite's API
documents, not recorded: events across two pages, statuses (on sale, sold out, sales not open
yet), one event whole (ticket classes, a sold-out class), attendees with `changed_since`, checks
valid, already used, refunded and unknown, a refused token; `BareTest`. Green in the family's
harness on 2026-10-26. Not run live: no Eventbrite token was used.

## Responsibility

This package is an independent, unofficial client, affiliated with Eventbrite in no way. Whoever
runs it keeps Eventbrite's [API terms](https://www.eventbrite.com/platform/api) and rate limits;
see the README.
