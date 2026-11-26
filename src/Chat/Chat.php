<?php

namespace Hibira\SymfonyBundle\Chat;

use Hibira\SymfonyBundle\Exception\ChatException;

/**
 * The Chat API (https://hibira.io/chat-api), from $hibira->chat(): conversations
 * with stored history, read receipts and offline push. Errors throw
 * ChatException (getCode() is the HTTP status).
 */
final class Chat
{
    /** @var \Closure(string, string, array<string, string>, array<string, mixed>|null): array<string, mixed> */
    private \Closure $send;

    /**
     * @param \Closure(string, string, array<string, string>, array<string, mixed>|null): array<string, mixed> $send
     */
    public function __construct(\Closure $send)
    {
        $this->send = $send;
    }

    /**
     * Any Chat API call, signed. $path is relative to /apps/APP_ID/chat, such
     * as "/conversations/c1/typing".
     *
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $body
     *
     * @return array<string, mixed>
     *
     * @throws ChatException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);
        if (!str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        if ($body === null && \in_array($method, ['POST', 'PUT'], true)) {
            $body = [];
        }
        $q = [];
        foreach ($query as $k => $v) {
            if ($v !== null) {
                $q[$k] = \is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            }
        }

        return ($this->send)($method, '/chat'.$path, $q, $body);
    }

    /**
     * Creates or replaces a conversation: name, members, metadata, roles, watchers.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public function putConversation(string $conversationId, array $fields): array
    {
        return $this->request('PUT', '/conversations/'.self::seg($conversationId), [], $fields);
    }

    /** @return array<string, mixed> */
    public function getConversation(string $conversationId): array
    {
        return $this->request('GET', '/conversations/'.self::seg($conversationId));
    }

    /**
     * Deletes a conversation with its members, messages and files.
     *
     * @return array<string, mixed>
     */
    public function deleteConversation(string $conversationId): array
    {
        return $this->request('DELETE', '/conversations/'.self::seg($conversationId));
    }

    /**
     * @param list<string> $userIds
     *
     * @return array<string, mixed>
     */
    public function addMembers(string $conversationId, array $userIds): array
    {
        return $this->request('POST', '/conversations/'.self::seg($conversationId).'/members', [], ['user_ids' => array_values(array_map('strval', $userIds))]);
    }

    /** @return array<string, mixed> */
    public function removeMember(string $conversationId, string $userId): array
    {
        return $this->request('DELETE', '/conversations/'.self::seg($conversationId).'/members/'.self::seg($userId));
    }

    /**
     * Posts a message as a member. $fields may add name, metadata, push
     * ("offline", "always", "never"), push_title, push_url, visible_to and
     * idempotency_key.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public function sendMessage(string $conversationId, string $userId, ?string $text = null, array $fields = []): array
    {
        $body = ['user_id' => $userId] + ($text === null ? [] : ['text' => $text]);

        return $this->request('POST', '/conversations/'.self::seg($conversationId).'/messages', [], $body + $fields);
    }

    /**
     * Messages oldest first after a seq (0 for the start), or the last $last
     * messages.
     *
     * @return array<string, mixed>
     */
    public function listMessages(string $conversationId, ?int $after = null, ?int $limit = null, ?int $last = null): array
    {
        return $this->request('GET', '/conversations/'.self::seg($conversationId).'/messages', ['after' => $after, 'limit' => $limit, 'last' => $last]);
    }

    /** @return array<string, mixed> */
    public function markRead(string $conversationId, string $userId, int $seq): array
    {
        return $this->request('POST', '/conversations/'.self::seg($conversationId).'/read', [], ['user_id' => $userId, 'seq' => $seq]);
    }

    /**
     * A system message (no sender), such as "Alice joined".
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public function systemMessage(string $conversationId, string $text, array $fields = []): array
    {
        return $this->request('POST', '/conversations/'.self::seg($conversationId).'/system', [], ['text' => $text] + $fields);
    }

    /**
     * A user's conversations, newest activity first.
     *
     * @return array<string, mixed>
     */
    public function userConversations(string $userId, ?int $limit = null, ?string $before = null): array
    {
        return $this->request('GET', '/users/'.self::seg($userId).'/conversations', ['limit' => $limit, 'before' => $before]);
    }

    private static function seg(string $id): string
    {
        if ($id === '') {
            throw new \InvalidArgumentException('An ID is required.');
        }

        return rawurlencode($id);
    }
}
