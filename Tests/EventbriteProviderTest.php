<?php

namespace Omniticket\Eventbrite\Tests;

use Omniticket\Eventbrite\EventbriteFactory;
use Omniticket\Eventbrite\EventbriteProvider;
use Omniticket\Exception\InvalidKeyException;
use Omniticket\Model\Attendee;
use Omniticket\Model\CheckResult;
use Omniticket\Model\Event;
use Omniticket\Model\EventQuery;
use Omniticket\Model\Rate;
use Omniticket\Registry;
use Omniticket\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Eventbrite's answers are composed for these tests from the objects its
 * API v3 documents (event, ticket_availability, ticket_class, attendee,
 * pagination); they were not recorded from an account. Every identifier is
 * an example.
 */
final class EventbriteProviderTest extends TestCase
{
    public function testEventsAcrossPages(): void
    {
        $http = self::http();
        $events = [...self::provider($http)->events()];

        self::assertSame([['100000000001', 'Jazz au jardin', 'on_sale', 'Jardin des deux rives, Strasbourg', '12.00', '25.00'], ['100000000002', 'Lecture sous les arbres', 'sold_out', null, '0.00', null]], array_map(static fn (Event $e) => [$e->id, $e->title, $e->status->value, $e->venue, $e->priceFrom?->major(), $e->priceTo?->major()], $events));
        self::assertSame('2099-07-14T17:00:00+00:00', $events[0]->start?->format(\DATE_ATOM));
        self::assertTrue($events[1]->soldOut());
        self::assertSame('https://www.eventbriteapi.com/v3/organizations/200000000001/events/', $http->requests[0]['url']);
        self::assertSame(['expand' => 'ticket_availability,venue', 'order_by' => 'start_asc', 'time_filter' => 'current_future'], $http->requests[0]['options']['query']);
        self::assertSame('example-token-not-real', $http->requests[0]['options']['auth_bearer']);
        $second = $http->requests[1]['options']['query'];
        self::assertIsArray($second);
        self::assertSame('example-continuation-2', $second['continuation']);
    }

    public function testAnEventWhole(): void
    {
        $event = self::provider(self::http())->event('100000000001');

        self::assertSame([['300000000001', 'Plein tarif', '25.00', 50, false], ['300000000002', 'Étudiant', '12.00', 0, true]], array_map(static fn (Rate $r) => [$r->id, $r->label, $r->price?->major(), $r->availability->remaining, $r->availability->soldOut], $event->rates));
        self::assertSame('12.00', $event->lowestPrice()?->major());
        self::assertSame(300, $event->availability->capacity);
    }

    public function testAttendeesIncrementallyAndChecks(): void
    {
        $http = self::http();
        $provider = self::provider($http);
        $attendees = [...$provider->attendees('100000000001', new \DateTimeImmutable('2099-05-01T10:00:00+02:00'))];

        self::assertSame(['changed_since' => '2099-05-01T08:00:00Z'], $http->requests[0]['options']['query']);
        self::assertSame([['400000000001', '111111111111111111111', 'Jeanne Dupont', 'valid'], ['400000000002', '222222222222222222222', 'Paul Martin', 'checked_in'], ['400000000003', '333333333333333333333', 'Ana Lima', 'refunded']], array_map(static fn (Attendee $a) => [$a->id, $a->code, $a->name(), $a->status->value], $attendees));
        self::assertSame('2099-07-14T17:21:00+00:00', $attendees[1]->checkedInAt?->format(\DATE_ATOM));
        self::assertSame([CheckResult::Valid, CheckResult::AlreadyUsed, CheckResult::Void, CheckResult::Unknown], array_map(static fn (string $code) => $provider->check('100000000001', $code)->result, ['111111111111111111111', '222222222222222222222', '333333333333333333333', '0']));
    }

    public function testARefusedToken(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('Eventbrite refused the credentials (401) for event "1": The OAuth token you provided was invalid.');
        self::provider(RecordedHttpClient::routes(['GET /events/1/' => [401, '{"status_code": 401, "error_description": "The OAuth token you provided was invalid.", "error": "INVALID_AUTH"}']]))->event('1');
    }

    public function testTheFactory(): void
    {
        $registry = new Registry([new EventbriteFactory(self::http())], ['eb' => ['factory' => 'eventbrite', 'options' => ['token' => 'example-token-not-real', 'organization' => '200000000001']]]);

        self::assertInstanceOf(EventbriteProvider::class, $registry->get('eb'));
        self::assertCount(2, [...$registry->catalog('eb')->events(new EventQuery(past: true))]);
    }

    private static function provider(RecordedHttpClient $http): EventbriteProvider
    {
        return new EventbriteProvider($http, 'example-token-not-real', '200000000001');
    }

    private static function http(): RecordedHttpClient
    {
        return new RecordedHttpClient(static function (string $method, string $url, array $options): array {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $query = \is_array($options['query'] ?? null) ? $options['query'] : [];

            return match (true) {
                str_ends_with($path, '/organizations/200000000001/events/') => [200, self::fixture(isset($query['continuation']) ? 'events-page-2' : 'events-page-1')],
                str_ends_with($path, '/events/100000000001/') => [200, self::fixture('event')],
                str_ends_with($path, '/ticket_classes/') => [200, self::fixture('ticket-classes')],
                str_ends_with($path, '/attendees/') => [200, self::fixture('attendees')],
                default => [404, '{"error": "NOT_FOUND", "error_description": "The path you requested does not exist."}'],
            };
        });
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name.'.json');
    }
}
