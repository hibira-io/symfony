<?php

namespace Hibira\SymfonyBundle\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Asks your app whether the browser may join a private or presence channel.
 * Nobody may until a listener calls allow():
 *
 *     #[AsEventListener]
 *     public function __invoke(ChannelAuthorizationEvent $event): void
 *     {
 *         if ($event->channel === 'private-orders.'.$event->user?->getId()) {
 *             $event->allow();
 *         }
 *     }
 *
 * For a presence channel, allow() can name the member; by default the member
 * ID is the signed-in user's identifier and there is no member info.
 */
final class ChannelAuthorizationEvent extends Event
{
    private bool $allowed = false;

    private ?string $memberId = null;

    /** @var array<string, mixed>|null */
    private ?array $memberInfo = null;

    public function __construct(
        public readonly string $channel,
        public readonly string $socketId,
        public readonly Request $request,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function isPresence(): bool
    {
        return str_starts_with($this->channel, 'presence-');
    }

    /**
     * @param array<string, mixed>|null $memberInfo presence channels: shown to the other members
     */
    public function allow(?string $memberId = null, ?array $memberInfo = null): void
    {
        $this->allowed = true;
        $this->memberId = $memberId;
        $this->memberInfo = $memberInfo;
        $this->stopPropagation();
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getMemberId(): ?string
    {
        return $this->memberId ?? $this->user?->getUserIdentifier();
    }

    /** @return array<string, mixed>|null */
    public function getMemberInfo(): ?array
    {
        return $this->memberInfo;
    }
}
