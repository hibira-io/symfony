<?php

namespace Hibira\SymfonyBundle\Controller;

use Hibira\SymfonyBundle\Hibira;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * GET /hibira/push/token?user_id=...: a push user token for the signed-in
 * user, for setUserId() in the web, Android and iOS push SDKs. The user_id
 * must be the signed-in user's identifier.
 */
final class PushTokenController
{
    public function __construct(
        private Hibira $hibira,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->hibira->hasPush()) {
            return new JsonResponse(['error' => 'Push notifications are not configured'], 404);
        }

        $user = $this->tokens?->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $userId = $user->getUserIdentifier();
        if (!hash_equals($userId, (string) $request->query->get('user_id', ''))) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return new JsonResponse($this->hibira->push()->generateToken($userId));
    }
}
