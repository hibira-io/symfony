<?php

namespace Hibira\SymfonyBundle\Tests\Fixtures;

use Hibira\SymfonyBundle\Event\ChannelAuthorizationEvent;
use Hibira\SymfonyBundle\Event\WebhookReceivedEvent;

final class TestListeners
{
    /** @var list<WebhookReceivedEvent> */
    public array $webhooks = [];

    public function onAuthorize(ChannelAuthorizationEvent $event): void
    {
        if ($event->channel === 'private-open') {
            $event->allow();
        }
        if ($event->channel === 'presence-room' && $event->user !== null) {
            $event->allow(null, ['name' => 'Ada']);
        }
        if ($event->channel === 'presence-guest') {
            $event->allow();
        }
    }

    public function onWebhook(WebhookReceivedEvent $event): void
    {
        $this->webhooks[] = $event;
    }
}
