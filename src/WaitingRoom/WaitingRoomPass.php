<?php

namespace Hibira\SymfonyBundle\WaitingRoom;

/** The claims of a valid waiting room pass. Times are Unix seconds. */
final class WaitingRoomPass
{
    public function __construct(
        public readonly string $appId,
        public readonly string $room,
        public readonly int $number,
        public readonly string $ticketId,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
    ) {
    }
}
