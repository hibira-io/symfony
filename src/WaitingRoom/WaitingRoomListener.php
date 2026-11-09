<?php

namespace Hibira\SymfonyBundle\WaitingRoom;

use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Enforces #[WaitingRoom] on controllers and the hibira.waiting_room.paths
 * prefixes on every main request.
 */
final class WaitingRoomListener
{
    public function __construct(private readonly WaitingRooms $rooms)
    {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $room = $this->rooms->roomForPath($event->getRequest()->getPathInfo());
        if (null !== $room && $response = $this->rooms->check($event->getRequest(), $room)) {
            $event->setResponse($response);
        }
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        foreach ($event->getAttributes()[WaitingRoom::class] ?? [] as $attribute) {
            if ($request->attributes->get(WaitingRooms::ATTRIBUTE)?->room === $attribute->room) {
                continue;
            }
            if ($response = $this->rooms->check($request, $attribute->room, $attribute->lang)) {
                $event->setController(static fn () => $response);

                return;
            }
        }
    }
}
