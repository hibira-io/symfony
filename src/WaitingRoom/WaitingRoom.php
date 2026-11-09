<?php

namespace Hibira\SymfonyBundle\WaitingRoom;

/**
 * Protects a controller or a whole controller class with a Hibira waiting
 * room: visitors without a valid pass for the room are sent to the queue.
 *
 *     #[WaitingRoom('presale')]
 *     public function drop(Request $request): Response
 *
 * The pass is in the request attribute "hibira_pass" (a {@see WaitingRoomPass}).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class WaitingRoom
{
    public function __construct(
        public readonly string $room,
        public readonly ?string $lang = null,
    ) {
    }
}
