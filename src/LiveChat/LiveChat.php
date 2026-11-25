<?php

namespace Hibira\SymfonyBundle\LiveChat;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Hibira Live Chat (https://chat.hibira.io) on your pages. {{ hibira_live_chat() }}
 * renders the widget; with an identity secret (Live Chat › Settings › Widget ›
 * Verified visitors) the signed-in user chats as themselves: their ID is
 * signed, so the inbox shows their name and email and nobody can pretend to
 * be them.
 */
final class LiveChat
{
    public const SCRIPT_URL = 'https://chat.hibira.io/w/v1.js';

    /** @var (\Closure(UserInterface): array{id?: string|int|null, name?: ?string, email?: ?string})|null */
    private ?\Closure $describeUser = null;

    public function __construct(
        private ?string $workspace = null,
        private ?string $identitySecret = null,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    /** hex(HMAC-SHA256(identity secret, user ID)): the widget's data-user-hash. */
    public static function userHash(string $identitySecret, string|int $userId): string
    {
        if ($identitySecret === '') {
            throw new \InvalidArgumentException('The Live Chat identity secret is required.');
        }

        return hash_hmac('sha256', (string) $userId, $identitySecret);
    }

    /**
     * Changes which ID, name and email are sent for a user. The default uses
     * getUserIdentifier(), and getId(), getName() / getFullName() and
     * getEmail() when the user has them.
     *
     * @param \Closure(UserInterface): array{id?: string|int|null, name?: ?string, email?: ?string} $describe
     */
    public function describeUserUsing(\Closure $describe): void
    {
        $this->describeUser = $describe;
    }

    public function workspace(): ?string
    {
        return ($this->workspace ?? '') === '' ? null : $this->workspace;
    }

    /**
     * The verified-visitor fields for a user, or null without a user or an
     * identity secret. Hand it to an SPA for HibiraLiveChat.identify().
     *
     * @return array{id: string, hash: string, name?: string, email?: string}|null
     */
    public function identity(?UserInterface $user): ?array
    {
        if ($user === null || ($this->identitySecret ?? '') === '') {
            return null;
        }
        $about = $this->describeUser ? ($this->describeUser)($user) : self::describe($user);
        $id = $about['id'] ?? null;
        if ($id === null || $id === '') {
            return null;
        }
        $out = ['id' => (string) $id, 'hash' => self::userHash($this->identitySecret, $id)];
        foreach (['name', 'email'] as $field) {
            if (isset($about[$field]) && $about[$field] !== '') {
                $out[$field] = (string) $about[$field];
            }
        }

        return $out;
    }

    /**
     * The script tag's attributes; empty without a workspace.
     *
     * @param array{workspace?: ?string, color?: ?string, lang?: ?string, launcher?: bool, nonce?: ?string} $options
     *
     * @return array<string, string|true>
     */
    public function attributes(array $options = []): array
    {
        $workspace = ($options['workspace'] ?? null) ?: $this->workspace();
        if ($workspace === null) {
            return [];
        }
        $a = ['src' => self::SCRIPT_URL, 'data-workspace' => $workspace];
        if (!empty($options['color'])) {
            $a['data-color'] = (string) $options['color'];
        }
        if (!empty($options['lang'])) {
            $a['data-lang'] = (string) $options['lang'];
        }
        if (($options['launcher'] ?? true) === false) {
            $a['data-launcher'] = 'none';
        }
        $user = $this->tokens?->getToken()?->getUser();
        if ($who = $this->identity($user instanceof UserInterface ? $user : null)) {
            $a['data-user-id'] = $who['id'];
            $a['data-user-hash'] = $who['hash'];
            if (isset($who['name'])) {
                $a['data-user-name'] = $who['name'];
            }
            if (isset($who['email'])) {
                $a['data-user-email'] = $who['email'];
            }
        }
        if (!empty($options['nonce'])) {
            $a['nonce'] = (string) $options['nonce'];
        }
        $a['async'] = true;

        return $a;
    }

    /**
     * @param array{workspace?: ?string, color?: ?string, lang?: ?string, launcher?: bool, nonce?: ?string} $options
     */
    public function render(array $options = []): string
    {
        $attributes = $this->attributes($options);
        if ($attributes === []) {
            return '';
        }
        $html = '<script';
        foreach ($attributes as $name => $value) {
            $html .= $value === true ? ' '.$name : sprintf(' %s="%s"', $name, htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $html.'></script>';
    }

    /**
     * @return array{id: string|int|null, name: ?string, email: ?string}
     */
    private static function describe(UserInterface $user): array
    {
        $call = static fn (string $method): mixed => method_exists($user, $method) ? $user->{$method}() : null;
        $name = $call('getFullName') ?? $call('getName');

        return [
            'id' => $call('getId') ?? $user->getUserIdentifier(),
            'name' => \is_string($name) ? $name : null,
            'email' => \is_string($email = $call('getEmail')) ? $email : null,
        ];
    }
}
