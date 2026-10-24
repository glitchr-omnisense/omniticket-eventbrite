<?php

namespace Omniticket\Eventbrite;

use Omniticket\AttendeeListInterface;
use Omniticket\Capabilities;
use Omniticket\EventCatalogInterface;
use Omniticket\Http\JsonApi;
use Omniticket\Model\Attendee;
use Omniticket\Model\AttendeeStatus;
use Omniticket\Model\Availability;
use Omniticket\Model\Check;
use Omniticket\Model\Event;
use Omniticket\Model\EventQuery;
use Omniticket\Model\EventStatus;
use Omniticket\Model\Money;
use Omniticket\Model\Rate;
use Omniticket\Support\Data;
use Omniticket\TicketCheckInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Eventbrite's API v3 (https://www.eventbrite.com/platform/api):
 * GET /organizations/{id}/events/, /events/{id}/ (ticket availability and
 * venue expanded), /events/{id}/ticket_classes/ and
 * /events/{id}/attendees/, with a bearer token; pages followed by their
 * continuation. Reading only.
 */
final class EventbriteProvider implements EventCatalogInterface, AttendeeListInterface, TicketCheckInterface
{
    private const EXPAND = 'ticket_availability,venue';

    private readonly JsonApi $api;

    public function __construct(
        HttpClientInterface $http,
        #[\SensitiveParameter] private readonly string $token,
        private readonly string $organization,
        private readonly string $url = 'https://www.eventbriteapi.com/v3',
    ) {
        $this->api = new JsonApi($http, 'Eventbrite');
    }

    public function getName(): string
    {
        return 'eventbrite';
    }

    public function getTitle(): string
    {
        return 'Eventbrite';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            catalog: true,
            attendees: true,
            check: true,
            incremental: true,
            terms: ['https://www.eventbrite.com/platform/api'],
            limits: ['per token: 2,000 calls an hour, 48,000 a day (figures as Eventbrite publishes them; not re-checked on 2026-10-24)'],
        );
    }

    public function events(EventQuery $query = new EventQuery()): iterable
    {
        return $query->filter($this->paged('/organizations/'.rawurlencode($this->organization).'/events/', ['expand' => self::EXPAND, 'order_by' => 'start_asc', 'time_filter' => $query->past ? 'all' : 'current_future'], 'events', 'the events', fn (Data $event) => $this->toEvent($event)));
    }

    public function event(string $id): Event
    {
        $event = $this->get('/events/'.rawurlencode($id).'/', ['expand' => self::EXPAND], 'event "'.$id.'"');
        $rates = [...$this->paged('/events/'.rawurlencode($id).'/ticket_classes/', [], 'ticket_classes', 'the ticket classes of event "'.$id.'"', static function (Data $class): Rate {
            $total = $class->int('quantity_total');
            $sold = $class->int('quantity_sold');

            return new Rate(
                (string) $class->string('id'),
                $class->string('name') ?? '',
                $class->bool('free') ? new Money(0, $class->string('cost.currency') ?? 'USD') : self::money($class->data('cost')),
                match (true) {
                    'SOLD_OUT' === $class->string('on_sale_status') => new Availability(0, $total, $sold, true),
                    null !== $total && null !== $sold => Availability::of(null, $total, $sold),
                    default => new Availability(),
                },
                $class->date('sales_start'),
                $class->date('sales_end'),
            );
        })];

        return $this->toEvent($event, $rates);
    }

    public function attendees(string $eventId, ?\DateTimeImmutable $updatedSince = null): iterable
    {
        $query = null !== $updatedSince ? ['changed_since' => $updatedSince->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')] : [];

        return $this->paged('/events/'.rawurlencode($eventId).'/attendees/', $query, 'attendees', 'the attendees of event "'.$eventId.'"', static function (Data $attendee) use ($eventId): Attendee {
            $barcode = $attendee->list('barcodes')[0] ?? new Data();

            return new Attendee(
                (string) $attendee->string('id'),
                $eventId,
                $barcode->string('barcode'),
                $attendee->string('profile.first_name'),
                $attendee->string('profile.last_name'),
                $attendee->string('profile.email'),
                $attendee->string('ticket_class_name'),
                match (true) {
                    $attendee->bool('refunded') || 'refunded' === $barcode->string('status') => AttendeeStatus::Refunded,
                    $attendee->bool('cancelled') || 'Not Attending' === $attendee->string('status') => AttendeeStatus::Cancelled,
                    $attendee->bool('checked_in') || 'used' === $barcode->string('status') => AttendeeStatus::CheckedIn,
                    'Unpaid' === $attendee->string('status') => AttendeeStatus::Pending,
                    default => AttendeeStatus::Valid,
                },
                $attendee->bool('checked_in') || 'used' === $barcode->string('status') ? $barcode->date('changed') : null,
                $attendee->string('order_id'),
            );
        });
    }

    /** Eventbrite has no lookup by barcode: the event's attendees are read, and the code looked for among them. */
    public function check(string $eventId, string $code): Check
    {
        return Check::among($this->attendees($eventId), $code);
    }

    /** @param list<Rate> $rates */
    private function toEvent(Data $event, array $rates = []): Event
    {
        $availability = $event->data('ticket_availability');
        $salesStart = $availability->date('start_sales_date.utc');
        $soldOut = $availability->bool('is_sold_out');
        $venue = implode(', ', array_filter([$event->string('venue.name'), $event->string('venue.address.city')]));

        return new Event(
            (string) $event->string('id'),
            $event->string('name.text') ?? '',
            $event->date('start.utc'),
            $event->date('end.utc'),
            match ($event->string('status')) {
                'draft' => EventStatus::Draft,
                'canceled' => EventStatus::Cancelled,
                'ended', 'completed' => EventStatus::Ended,
                'live', 'started' => match (true) {
                    $soldOut => EventStatus::SoldOut,
                    null !== $salesStart && $salesStart > new \DateTimeImmutable() => EventStatus::Upcoming,
                    $availability->has('has_available_tickets') && !$availability->bool('has_available_tickets') => EventStatus::SalesClosed,
                    default => EventStatus::OnSale,
                },
                default => EventStatus::Unknown,
            },
            $event->string('url'),
            '' !== $venue ? $venue : null,
            $soldOut ? new Availability(0, $event->int('capacity'), null, true) : new Availability(capacity: $event->int('capacity')),
            $rates,
            priceFrom: self::money($availability->data('minimum_ticket_price')),
            priceTo: self::money($availability->data('maximum_ticket_price')),
            provider: 'eventbrite',
        );
    }

    /** Eventbrite's currency object: {currency, value (minor units), major_value, display}. */
    private static function money(Data $cost): ?Money
    {
        $currency = $cost->string('currency');
        $value = $cost->int('value');

        return null !== $currency && null !== $value ? new Money($value, $currency) : null;
    }

    /**
     * Every page of a list, by continuation.
     *
     * @template T
     *
     * @param array<string, string> $query
     * @param \Closure(Data): T     $map
     *
     * @return \Generator<int, T>
     */
    private function paged(string $path, array $query, string $key, string $what, \Closure $map): \Generator
    {
        $continuation = null;
        do {
            $page = $this->get($path, $query + (null !== $continuation ? ['continuation' => $continuation] : []), $what);
            foreach ($page->list($key) as $line) {
                yield $map($line);
            }
            $continuation = $page->string('pagination.continuation');
        } while ($page->bool('pagination.has_more_items') && null !== $continuation);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query, string $what): Data
    {
        return $this->api->request('GET', $this->url.$path, ['query' => $query, 'auth_bearer' => $this->token], $what);
    }
}
