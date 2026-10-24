<?php

namespace Omniticket\Eventbrite;

use Omniticket\Config;
use Omniticket\Exception\InvalidConfigException;
use Omniticket\ProviderFactory;
use Omniticket\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Eventbrite, from its options:
 *
 *   token         a private token (account settings, "Developer links", "API keys") or an OAuth token
 *   organization  the organization's id, whose events are listed (GET /users/me/organizations/ gives it)
 *   url           https://www.eventbriteapi.com/v3
 */
final class EventbriteFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omniticket.factory_name' => 'eventbrite',
            'omniticket.factory_title' => 'Eventbrite',
            'omniticket.required_options' => ['token', 'organization'],
            'url' => 'https://www.eventbriteapi.com/v3',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new EventbriteProvider(
            $this->http ?? throw new InvalidConfigException('The "eventbrite" provider needs an HTTP client: give one to EventbriteFactory.'),
            (string) $c->string('token'),
            (string) $c->string('organization'),
            rtrim($c->string('url') ?? 'https://www.eventbriteapi.com/v3', '/'),
        );
    }
}
