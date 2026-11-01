<?php

namespace Hibira\SymfonyBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * One event from a verified Hibira webhook: channel_occupied,
 * channel_vacated, member_added, member_removed, client_event, ...
 *
 *     #[AsEventListener]
 *     public function __invoke(WebhookReceivedEvent $event): void
 *     {
 *         if ($event->name === 'member_removed') { ... $event->payload['user_id'] ... }
 *     }
 */
final class WebhookReceivedEvent extends Event
{
    /**
     * @param array<string, mixed> $payload the whole event, as Hibira sent it
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $channel,
        public readonly array $payload,
        public readonly int $timeMs,
    ) {
    }
}
