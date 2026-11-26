<?php

namespace Hibira\SymfonyBundle;

use Hibira\SymfonyBundle\Chat\Chat;
use Hibira\SymfonyBundle\Exception\ApprovalException;
use Hibira\SymfonyBundle\Exception\ChatException;
use Hibira\SymfonyBundle\Exception\MissingCredentialsException;
use Hibira\SymfonyBundle\Exception\PayloadTooLargeException;
use Hibira\SymfonyBundle\Message\PublishEvent;
use Hibira\SymfonyBundle\Turbo\TurboStreams;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Pusher\PushNotifications\PushNotifications;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends events to Hibira and signs channel subscriptions.
 *
 * publish() goes through Symfony Messenger when it is installed, so routing
 * PublishEvent to an async transport moves the HTTP call to a worker, the
 * way Mercure's Update does. trigger() always sends right away.
 */
class Hibira
{
    public const DEFAULT_HOST = 'ws.hibira.io';

    public const DEFAULT_PUSH_ENDPOINT = 'https://ws.hibira.io';

    /** The Channels HTTP API takes at most this many channels per event. */
    public const MAX_CHANNELS_PER_EVENT = 100;

    private ?Pusher $pusher = null;

    private ?PushNotifications $push = null;

    /**
     * @param array{app_id: ?string, key: ?string, secret: ?string, host: string, port: int, scheme: string, push: array{instance_id: ?string, secret_key: ?string, endpoint: string}} $config
     */
    public function __construct(
        private array $config,
        private ?MessageBusInterface $bus = null,
        private ?LoggerInterface $logger = null,
        private ?ClientInterface $httpClient = null,
    ) {
        $this->config['port'] = (int) ($this->config['port'] ?? 443);
        $this->config['scheme'] = strtolower((string) ($this->config['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';
        $this->config['host'] = (string) (($this->config['host'] ?? '') ?: self::DEFAULT_HOST);
    }

    /**
     * Sends an event to one or more channels, through Messenger when it is
     * installed (synchronously unless PublishEvent is routed to a transport).
     *
     * @param string|list<string> $channels
     * @param mixed               $data              anything json_encode() takes; strings arrive as strings
     * @param mixed               $oversizeFallback  sent instead when the app's event size limit rejects $data
     */
    public function publish(string|array $channels, string $event, mixed $data, ?string $socketId = null, mixed $oversizeFallback = null): void
    {
        $message = new PublishEvent((array) $channels, $event, $data, $socketId, $oversizeFallback);

        if ($this->bus !== null) {
            $this->bus->dispatch($message);

            return;
        }

        $this->send($message);
    }

    /**
     * Sends Turbo Stream HTML to every page listening to the topics with
     * turbo_stream_listen() or turbo_stream_from(), like a #[Broadcast]
     * entity does. A stream over the event size limit becomes a page refresh.
     *
     * @param string|list<string> $topics
     */
    public function turboStream(string|array $topics, string $html): void
    {
        $fallback = str_contains($html, 'action="refresh"') ? null : TurboStreams::REFRESH;
        $this->publish(array_map(TurboStreams::channel(...), (array) $topics), TurboStreams::EVENT, $html, null, $fallback);
    }

    /**
     * Sends an event now, skipping Messenger.
     *
     * @param string|list<string> $channels
     */
    public function trigger(string|array $channels, string $event, mixed $data, ?string $socketId = null): void
    {
        $this->send(new PublishEvent((array) $channels, $event, $data, $socketId));
    }

    /** @internal called by PublishEventHandler */
    public function send(PublishEvent $message): void
    {
        $channels = array_values(array_unique($message->channels));
        $params = $message->socketId !== null ? ['socket_id' => $message->socketId] : [];

        foreach (array_chunk($channels, self::MAX_CHANNELS_PER_EVENT) as $chunk) {
            try {
                $this->pusher()->trigger($chunk, $message->event, $message->data, $params);
            } catch (ApiErrorException $e) {
                if ($e->getCode() !== 413) {
                    throw $e;
                }

                $text = sprintf('Hibira event "%s" for %s is over the app\'s event size limit', $message->event, implode(', ', $chunk));
                if ($message->oversizeFallback === null) {
                    throw new PayloadTooLargeException($text.'. Upgrade the plan or send less data.', 413, $e);
                }

                $this->logger?->warning($text.'; sent the fallback instead.', ['channels' => $chunk, 'event' => $message->event]);
                $this->pusher()->trigger($chunk, $message->event, $message->oversizeFallback, $params);
            }
        }
    }

    /**
     * The auth signature JSON for a private channel subscription.
     */
    public function authorizeChannel(string $channel, string $socketId): string
    {
        return $this->pusher()->authorizeChannel($channel, $socketId);
    }

    /**
     * The auth signature JSON for a presence channel subscription.
     *
     * @param array<string, mixed>|null $userInfo
     */
    public function authorizePresenceChannel(string $channel, string $socketId, string $userId, ?array $userInfo = null): string
    {
        return $this->pusher()->authorizePresenceChannel($channel, $socketId, $userId, $userInfo);
    }

    public const APPROVAL_MAX_WAIT = 25;

    private const APPROVAL_FIELDS = ['details', 'options', 'expires_in_minutes', 'channel', 'push_interests', 'push_users', 'agent', 'approvers', 'quorum', 'callback_url'];

    /**
     * Asks a person for a decision (Hibira Approvals). Share the returned
     * "url" with whoever decides, then read the answer with approval() or
     * waitForApproval(). In $fields, options are 2 to 4 choices (the first
     * approves) and expires_in_minutes is 1 to 10080 (default 60).
     *
     * @param array{details?: string, options?: list<string>, expires_in_minutes?: int, channel?: string, push_interests?: list<string>, push_users?: list<string>, agent?: string, approvers?: list<string>, quorum?: int, callback_url?: string} $fields
     *
     * @return array<string, mixed> the approval: approval_id, status, url, options, ...
     */
    public function requestApproval(string $title, array $fields = []): array
    {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('An approval needs a title.');
        }
        $unknown = array_diff(array_keys($fields), self::APPROVAL_FIELDS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown approval fields: '.implode(', ', $unknown));
        }

        return $this->approvalsRequest('POST', '/approvals', [], ['title' => $title] + array_filter($fields, fn ($v) => $v !== null));
    }

    /**
     * An approval's status (pending, approved, rejected or expired). $wait
     * (seconds, up to 25) holds the request until it is answered.
     *
     * @return array<string, mixed>
     */
    public function approval(string $approvalId, int $wait = 0): array
    {
        if ($approvalId === '') {
            throw new \InvalidArgumentException('An approval ID is required.');
        }
        $wait = max(0, min(self::APPROVAL_MAX_WAIT, $wait));

        return $this->approvalsRequest('GET', '/approvals/'.rawurlencode($approvalId), $wait > 0 ? ['wait' => (string) $wait] : [], null, $wait + 10);
    }

    /**
     * Answers an approver's question on a pending request (the approval has
     * "awaiting_reply" => true). $details, when given, replaces the request's
     * details for the approvers.
     *
     * @return array<string, mixed>
     */
    public function replyToApproval(string $approvalId, string $message, ?string $details = null): array
    {
        if ($approvalId === '' || trim($message) === '') {
            throw new \InvalidArgumentException('An approval ID and a message are required.');
        }

        return $this->approvalsRequest('POST', '/approvals/'.rawurlencode($approvalId).'/replies', [], ['message' => $message] + ($details === null ? [] : ['details' => $details]));
    }

    /**
     * Polls until the approval is no longer pending, or $timeout seconds
     * pass, and returns it.
     *
     * @return array<string, mixed>
     */
    public function waitForApproval(string $approvalId, int $timeout = 3600): array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $left = (int) ceil($deadline - microtime(true));
            $approval = $this->approval($approvalId, min(self::APPROVAL_MAX_WAIT, max(0, $left)));
            if (($approval['status'] ?? null) !== 'pending' || $left <= self::APPROVAL_MAX_WAIT) {
                return $approval;
            }
        }
    }

    /**
     * @param array<string, string>     $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function approvalsRequest(string $method, string $resource, array $query, ?array $body, int $timeout = 15): array
    {
        return $this->signedRequest($method, $resource, $query, $body, $timeout, ApprovalException::class);
    }

    /**
     * The Chat API (https://hibira.io/chat-api): conversations, messages,
     * read receipts. Users receive chat.* events after pusher.signin().
     */
    public function chat(): Chat
    {
        return new Chat(fn (string $method, string $resource, array $query, ?array $body) => $this->signedRequest($method, $resource, $query, $body, 15, ChatException::class));
    }

    /**
     * A signed Channels-protocol HTTP API request under /apps/APP_ID.
     *
     * @param array<string, string>                                   $query
     * @param array<string, mixed>|null                               $body
     * @param class-string<ApprovalException>|class-string<ChatException> $exception
     *
     * @return array<string, mixed>
     */
    private function signedRequest(string $method, string $resource, array $query, ?array $body, int $timeout, string $exception): array
    {
        $missing = $this->missingCredentials();
        if ($missing !== []) {
            throw MissingCredentialsException::realtime($missing);
        }
        $path = '/apps/'.$this->config['app_id'].$resource;
        $json = $body === null ? null : json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== null) {
            $query['body_md5'] = md5($json);
        }
        // The server checks the signature over the decoded path ("order 42", not order%2042).
        $signed = Pusher::build_auth_query_params((string) $this->config['key'], (string) $this->config['secret'], $method, rawurldecode($path), $query);
        $options = [
            'query' => $signed,
            'http_errors' => false,
            'timeout' => $timeout,
            'headers' => ['Content-Type' => 'application/json', 'User-Agent' => 'hibira-symfony'],
        ];
        if ($json !== null) {
            $options['body'] = $json;
        }
        $url = $this->config['scheme'].'://'.$this->config['host'].':'.$this->config['port'].$path;
        $response = ($this->httpClient ?? new Client())->request($method, $url, $options);
        $text = (string) $response->getBody();
        $data = json_decode($text, true);
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300 && \is_array($data)) {
            return $data;
        }
        if ($status >= 200 && $status < 300 && trim($text) === '') {
            return [];
        }
        $message = \is_array($data) && isset($data['error']) ? (string) $data['error'] : trim($text);

        throw new $exception($status, $message !== '' ? $message : 'HTTP '.$status, $text);
    }

    /**
     * The Pusher-protocol client, for the rest of the Channels HTTP API
     * (getChannels(), getPresenceUsers(), ...).
     */
    public function pusher(): Pusher
    {
        if ($this->pusher !== null) {
            return $this->pusher;
        }

        $missing = $this->missingCredentials();
        if ($missing !== []) {
            throw MissingCredentialsException::realtime($missing);
        }

        $scheme = $this->config['scheme'];

        return $this->pusher = new Pusher(
            (string) $this->config['key'],
            (string) $this->config['secret'],
            (string) $this->config['app_id'],
            [
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'scheme' => $scheme,
                'useTLS' => $scheme === 'https',
            ],
            $this->httpClient,
        );
    }

    /**
     * The Beams-compatible push notifications client.
     */
    public function push(): PushNotifications
    {
        if ($this->push !== null) {
            return $this->push;
        }

        if (!class_exists(PushNotifications::class)) {
            throw new \LogicException('Push notifications need pusher/pusher-push-notifications. Try running "composer require pusher/pusher-push-notifications".');
        }

        $push = $this->config['push'];
        foreach (['instance_id', 'secret_key'] as $name) {
            if (self::blank($push[$name] ?? null)) {
                throw MissingCredentialsException::push($name);
            }
        }

        return $this->push = new PushNotifications([
            'instanceId' => (string) $push['instance_id'],
            'secretKey' => (string) $push['secret_key'],
            // Without an endpoint the SDK would talk to pusher.com.
            'endpoint' => rtrim($push['endpoint'] ?: self::DEFAULT_PUSH_ENDPOINT, '/'),
        ]);
    }

    public function hasPush(): bool
    {
        return !self::blank($this->config['push']['instance_id'] ?? null) && !self::blank($this->config['push']['secret_key'] ?? null);
    }

    /** @return list<string> config keys of missing realtime credentials */
    public function missingCredentials(): array
    {
        return array_values(array_filter(['app_id', 'key', 'secret'], fn (string $name) => self::blank($this->config[$name] ?? null)));
    }

    /**
     * Settings a browser needs to connect (never the secret).
     *
     * @return array{key: string, host: string, port: int, tls: bool}
     */
    public function clientConfig(): array
    {
        return [
            'key' => (string) $this->config['key'],
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'tls' => $this->config['scheme'] === 'https',
        ];
    }

    /**
     * An HMAC of $value under the app secret, for values the browser hands
     * back to us (Turbo stream channel names).
     */
    public function sign(string $value): string
    {
        $secret = (string) ($this->config['secret'] ?? '');
        if ($secret === '') {
            throw MissingCredentialsException::realtime(['secret']);
        }

        return hash_hmac('sha256', 'hibira-symfony:'.$value, $secret);
    }

    public function verify(string $value, string $signature): bool
    {
        return !self::blank($this->config['secret'] ?? null) && hash_equals($this->sign($value), $signature);
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Checks a webhook body against X-Pusher-Signature, or, while a rotated
     * secret is in its grace period, X-Hibira-Previous-Signature.
     *
     * @param array<string, string|null> $signatures header values
     */
    public function verifyWebhook(string $body, ?string $key, array $signatures): bool
    {
        $appKey = (string) ($this->config['key'] ?? '');
        $secret = (string) ($this->config['secret'] ?? '');
        if ($appKey === '' || $secret === '' || !hash_equals($appKey, (string) $key)) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $secret);
        foreach ($signatures as $signature) {
            if (\is_string($signature) && $signature !== '' && hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }
}
