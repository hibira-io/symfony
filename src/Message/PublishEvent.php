<?php

namespace Hibira\SymfonyBundle\Message;

/**
 * One Hibira event. Hibira::publish() dispatches it on the message bus;
 * route it to an async transport to send events from a worker:
 *
 *     # config/packages/messenger.yaml
 *     framework:
 *         messenger:
 *             routing:
 *                 Hibira\SymfonyBundle\Message\PublishEvent: async
 */
final class PublishEvent
{
    /**
     * @param list<string> $channels
     * @param mixed        $oversizeFallback sent instead when the app's event size limit rejects $data
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $event,
        public readonly mixed $data,
        public readonly ?string $socketId = null,
        public readonly mixed $oversizeFallback = null,
    ) {
    }
}
