<?php

namespace Hibira\SymfonyBundle\Controller;

use Hibira\SymfonyBundle\Event\ChannelAuthorizationEvent;
use Hibira\SymfonyBundle\Hibira;
use Hibira\SymfonyBundle\Turbo\TurboStreams;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * POST /hibira/auth: signs private and presence channel subscriptions.
 *
 * Turbo stream channels are signed when the page rendered
 * turbo_stream_listen(), so they need no listener. Every other channel is
 * up to your ChannelAuthorizationEvent listeners.
 */
final class ChannelAuthController
{
    private const CHANNEL = '/^(private|presence)-[A-Za-z0-9_\-=@,.;]+$/';

    public function __construct(
        private Hibira $hibira,
        private EventDispatcherInterface $dispatcher,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $socketId = (string) $request->request->get('socket_id', '');
        $channel = (string) $request->request->get('channel_name', '');

        if (!preg_match('/^\d+\.\d+$/', $socketId) || !preg_match(self::CHANNEL, $channel) || \strlen($channel) > 200) {
            return new JsonResponse(['error' => 'Invalid socket_id or channel_name'], 400);
        }

        if (TurboStreams::isTurboChannel($channel)) {
            $signature = (string) $request->request->get('signature', '');
            if (!$this->hibira->verify($channel, $signature)) {
                return new JsonResponse(['error' => 'Forbidden'], 403);
            }

            return $this->json($this->hibira->authorizeChannel($channel, $socketId));
        }

        $event = new ChannelAuthorizationEvent($channel, $socketId, $request, $this->user());
        $this->dispatcher->dispatch($event);

        if (!$event->isAllowed()) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        if (!$event->isPresence()) {
            return $this->json($this->hibira->authorizeChannel($channel, $socketId));
        }

        $memberId = $event->getMemberId();
        if ($memberId === null || $memberId === '') {
            return new JsonResponse(['error' => 'A presence channel needs a member ID: sign the user in, or pass one to allow()'], 403);
        }

        return $this->json($this->hibira->authorizePresenceChannel($channel, $socketId, $memberId, $event->getMemberInfo()));
    }

    private function json(string $body): JsonResponse
    {
        return JsonResponse::fromJsonString($body);
    }

    private function user(): ?UserInterface
    {
        $user = $this->tokens?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
