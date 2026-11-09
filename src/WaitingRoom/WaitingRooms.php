<?php

namespace Hibira\SymfonyBundle\WaitingRoom;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the signed pass a visitor brings back from the Hibira waiting room,
 * and decides what to do with a request.
 *
 * A request without a valid pass is redirected to the hosted waiting page
 * (non-GET requests get a 403). When it's their turn the page sends the
 * visitor back with ?hibira_pass=..., which is checked, stored in an
 * HttpOnly cookie (hibira_wr_<room>) and removed from the URL.
 */
final class WaitingRooms
{
    public const ATTRIBUTE = 'hibira_pass';
    private const PARAM = 'hibira_pass';
    private const PURPOSE = 'hibira-waitroom-pass.';

    /**
     * @param list<string>          $secrets the app secret, then any previous one during a rotation
     * @param array<string, string> $paths   path prefix => room, for the request listener
     */
    public function __construct(
        private readonly string $appId,
        private readonly string $appKey,
        private readonly array $secrets,
        private readonly string $host,
        private readonly ?string $lang = null,
        private readonly array $paths = [],
    ) {
    }

    /**
     * Returns the pass's claims, or null when it is missing, forged, expired,
     * or for another room or app.
     *
     * @param string|list<string> $secret
     */
    public static function verifyPass(mixed $token, string|array $secret, ?string $room = null, ?string $appId = null, ?int $now = null): ?WaitingRoomPass
    {
        if (!\is_string($token) || \strlen($token) > 1024 || 1 !== substr_count($token, '.')) {
            return null;
        }
        [$payload, $sigPart] = explode('.', $token, 2);
        $sig = self::decode($sigPart);
        if ('' === $payload || null === $sig || 32 !== \strlen($sig)) {
            return null;
        }
        $ok = false;
        foreach ((array) $secret as $s) {
            if (\is_string($s) && '' !== $s && hash_equals(hash_hmac('sha256', self::PURPOSE.$payload, $s, true), $sig)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return null;
        }
        $claims = json_decode((string) self::decode($payload), true);
        if (!\is_array($claims) || 1 !== ($claims['v'] ?? null) || !\is_int($claims['exp'] ?? null) && !\is_float($claims['exp'] ?? null)) {
            return null;
        }
        if (($now ?? time()) >= $claims['exp']) {
            return null;
        }
        if (null !== $room && ($claims['room'] ?? null) !== $room || null !== $appId && ($claims['app'] ?? null) !== $appId) {
            return null;
        }

        return new WaitingRoomPass(
            (string) ($claims['app'] ?? ''),
            (string) ($claims['room'] ?? ''),
            (int) ($claims['n'] ?? 0),
            (string) ($claims['tid'] ?? ''),
            (int) ($claims['iat'] ?? 0),
            (int) $claims['exp'],
        );
    }

    /** The hosted waiting page address for a room. */
    public function url(string $room, ?string $returnUrl = null, ?string $lang = null): string
    {
        $query = array_filter(['return' => $returnUrl, 'lang' => $lang ?? $this->lang], fn ($v) => null !== $v && '' !== $v);

        return rtrim($this->host, '/').'/wait/'.rawurlencode($this->appKey).'/'.rawurlencode($room)
            .($query ? '?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986) : '');
    }

    /** The room protecting this path (from the configured prefixes), or null. */
    public function roomForPath(string $path): ?string
    {
        $best = null;
        foreach ($this->paths as $prefix => $room) {
            $prefix = (string) $prefix;
            $match = $path === $prefix || str_starts_with($path, str_ends_with($prefix, '/') ? $prefix : $prefix.'/');
            if ($match && (null === $best || \strlen($prefix) > \strlen($best[0]))) {
                $best = [$prefix, $room];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * Returns null to let the request through (the pass is then in the
     * request attribute "hibira_pass"), or the response to send instead.
     */
    public function check(Request $request, string $room, ?string $lang = null): ?Response
    {
        if (!array_filter($this->secrets)) {
            throw new \LogicException('Waiting rooms need the app secret (HIBIRA_APP_SECRET).');
        }
        $cookieName = 'hibira_wr_'.$room;
        $appId = '' === $this->appId ? null : $this->appId;
        $clean = self::urlWithoutPass($request);

        $fresh = $request->query->get(self::PARAM);
        if (\is_string($fresh) && $pass = self::verifyPass($fresh, $this->secrets, $room, $appId)) {
            $response = new RedirectResponse($clean);
            $response->headers->setCookie(Cookie::create($cookieName, $fresh, $pass->expiresAt, '/', null, $request->isSecure(), true, true, Cookie::SAMESITE_LAX));
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        if ($pass = self::verifyPass($request->cookies->get($cookieName), $this->secrets, $room, $appId)) {
            $request->attributes->set(self::ATTRIBUTE, $pass);

            return null;
        }

        $response = \in_array($request->getMethod(), ['GET', 'HEAD'], true)
            ? new RedirectResponse($this->url($room, $clean, $lang))
            : new JsonResponse(['error' => 'waiting_room', 'message' => 'join the waiting room first'], 403);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private static function urlWithoutPass(Request $request): string
    {
        $base = $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo();
        $query = array_values(array_filter(
            explode('&', (string) $request->server->get('QUERY_STRING', '')),
            fn (string $part) => '' !== $part && self::PARAM !== $part && !str_starts_with($part, self::PARAM.'='),
        ));

        return $query ? $base.'?'.implode('&', $query) : $base;
    }

    private static function decode(string $value): ?string
    {
        if ('' === $value || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return null;
        }
        $out = base64_decode(strtr($value, '-_', '+/'), true);

        return false === $out ? null : $out;
    }
}
