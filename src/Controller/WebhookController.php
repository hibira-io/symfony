<?php

namespace Hibira\SymfonyBundle\Controller;

use Hibira\SymfonyBundle\Event\WebhookReceivedEvent;
use Hibira\SymfonyBundle\Hibira;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * POST /hibira/webhook: checks the signature and dispatches one
 * WebhookReceivedEvent per event in the body.
 */
final class WebhookController
{
    public function __construct(
        private Hibira $hibira,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();
        $valid = $this->hibira->verifyWebhook($body, $request->headers->get('X-Pusher-Key'), [
            $request->headers->get('X-Pusher-Signature'),
            $request->headers->get('X-Hibira-Previous-Signature'),
        ]);
        if (!$valid) {
            return new JsonResponse(['error' => 'Invalid webhook signature'], 401);
        }

        $data = json_decode($body, true);
        if (!\is_array($data) || !\is_array($data['events'] ?? null)) {
            return new JsonResponse(['error' => 'Invalid webhook body'], 400);
        }

        $timeMs = (int) ($data['time_ms'] ?? 0);
        foreach ($data['events'] as $event) {
            if (!\is_array($event) || !\is_string($event['name'] ?? null)) {
                continue;
            }
            $channel = \is_string($event['channel'] ?? null) ? $event['channel'] : null;
            $this->dispatcher->dispatch(new WebhookReceivedEvent($event['name'], $channel, $event, $timeMs));
        }

        return new Response('', 200);
    }
}
