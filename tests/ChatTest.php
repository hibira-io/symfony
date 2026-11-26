<?php

namespace Hibira\SymfonyBundle\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Hibira\SymfonyBundle\Exception\ChatException;
use Hibira\SymfonyBundle\Hibira;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ChatTest extends TestCase
{
    private array $history = [];

    private MockHandler $responses;

    private function hibira(): Hibira
    {
        $this->responses = new MockHandler();
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));

        return new Hibira([
            'app_id' => '1',
            'key' => 'app-key',
            'secret' => 'app-secret',
            'host' => 'hibira.test',
            'port' => 443,
            'scheme' => 'https',
            'push' => ['instance_id' => null, 'secret_key' => null, 'endpoint' => Hibira::DEFAULT_PUSH_ENDPOINT],
        ], null, null, new Client(['handler' => $stack]));
    }

    private function assertSigned(RequestInterface $request): void
    {
        parse_str($request->getUri()->getQuery(), $params);
        $signature = $params['auth_signature'];
        unset($params['auth_signature']);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k.'='.$v;
        }
        $expected = hash_hmac('sha256', $request->getMethod()."\n".$request->getUri()->getPath()."\n".implode('&', $pairs), 'app-secret');
        $this->assertSame($expected, $signature);
        $body = (string) $request->getBody();
        if ($body !== '') {
            $this->assertSame(md5($body), $params['body_md5']);
        }
    }

    private function last(): RequestInterface
    {
        return $this->history[\count($this->history) - 1]['request'];
    }

    private function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $params);
        unset($params['auth_key'], $params['auth_timestamp'], $params['auth_version'], $params['auth_signature'], $params['body_md5']);

        return $params;
    }

    public function testPutConversationSendsASignedPut(): void
    {
        $chat = $this->hibira()->chat();
        $this->responses->append(new Response(200, [], '{"id":"order 1","members":["u1","u2"]}'));

        $out = $chat->putConversation('order 1', ['name' => 'Order 1', 'members' => ['u1', 'u2']]);

        $this->assertSame(['u1', 'u2'], $out['members']);
        $request = $this->last();
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/apps/1/chat/conversations/order%201', $request->getUri()->getPath());
        $this->assertSame(['name' => 'Order 1', 'members' => ['u1', 'u2']], json_decode((string) $request->getBody(), true));
        $this->assertSigned($request);
    }

    public function testGetAndDeleteConversation(): void
    {
        $chat = $this->hibira()->chat();
        $this->responses->append(new Response(200, [], '{"id":"c1"}'), new Response(204, [], ''));

        $this->assertSame('c1', $chat->getConversation('c1')['id']);
        $this->assertSame('GET', $this->last()->getMethod());
        $this->assertSame('', (string) $this->last()->getBody());
        $this->assertSigned($this->last());

        $this->assertSame([], $chat->deleteConversation('c1'));
        $this->assertSame('DELETE', $this->last()->getMethod());
        $this->assertSame('/apps/1/chat/conversations/c1', $this->last()->getUri()->getPath());
        $this->assertSigned($this->last());
    }

    public function testMembers(): void
    {
        $chat = $this->hibira()->chat();
        $this->responses->append(new Response(200, [], '{"ok":true}'), new Response(200, [], '{"ok":true}'));

        $chat->addMembers('c1', ['u3', 4]);
        $this->assertSame('/apps/1/chat/conversations/c1/members', $this->last()->getUri()->getPath());
        $this->assertSame(['user_ids' => ['u3', '4']], json_decode((string) $this->last()->getBody(), true));

        $chat->removeMember('c1', 'u/3');
        $this->assertSame('DELETE', $this->last()->getMethod());
        $this->assertSame('/apps/1/chat/conversations/c1/members/u%2F3', $this->last()->getUri()->getPath());
        $this->assertSigned($this->last());
    }

    public function testMessages(): void
    {
        $chat = $this->hibira()->chat();
        $this->responses->append(
            new Response(201, [], '{"seq":7}'),
            new Response(200, [], '{"messages":[]}'),
            new Response(200, [], '{"ok":true}'),
            new Response(201, [], '{"seq":8}'),
        );

        $this->assertSame(7, $chat->sendMessage('c1', 'u1', 'Hi', ['push' => 'offline'])['seq']);
        $this->assertSame(['user_id' => 'u1', 'text' => 'Hi', 'push' => 'offline'], json_decode((string) $this->last()->getBody(), true));
        $this->assertSigned($this->last());

        $chat->listMessages('c1', after: 5, limit: 20);
        $this->assertSame('GET', $this->last()->getMethod());
        $this->assertSame(['after' => '5', 'limit' => '20'], $this->query($this->last()));
        $this->assertSigned($this->last());

        $chat->markRead('c1', 'u1', 7);
        $this->assertSame('/apps/1/chat/conversations/c1/read', $this->last()->getUri()->getPath());
        $this->assertSame(['user_id' => 'u1', 'seq' => 7], json_decode((string) $this->last()->getBody(), true));

        $chat->systemMessage('c1', 'Alice joined');
        $this->assertSame('/apps/1/chat/conversations/c1/system', $this->last()->getUri()->getPath());
        $this->assertSame(['text' => 'Alice joined'], json_decode((string) $this->last()->getBody(), true));
    }

    public function testUserConversationsAndRawRequest(): void
    {
        $chat = $this->hibira()->chat();
        $this->responses->append(new Response(200, [], '{"conversations":[]}'), new Response(200, [], '{}'));

        $chat->userConversations('u1', limit: 10, before: 'c9');
        $this->assertSame('/apps/1/chat/users/u1/conversations', $this->last()->getUri()->getPath());
        $this->assertEquals(['limit' => '10', 'before' => 'c9'], $this->query($this->last()));
        $this->assertSigned($this->last());

        $chat->request('post', 'conversations/c1/typing', [], null);
        $this->assertSame('POST', $this->last()->getMethod());
        $this->assertSame('/apps/1/chat/conversations/c1/typing', $this->last()->getUri()->getPath());
        $this->assertSame('{}', (string) $this->last()->getBody());
        $this->assertSigned($this->last());
    }

    public function testErrorsThrowChatException(): void
    {
        $chat = $this->hibira()->chat();
        $this->responses->append(new Response(402, [], '{"error":"The Chat API is not enabled for this account"}'));

        try {
            $chat->getConversation('c1');
            $this->fail('expected ChatException');
        } catch (ChatException $e) {
            $this->assertSame(402, $e->getCode());
            $this->assertStringContainsString('not enabled', $e->getMessage());
        }
    }

    public function testEmptyIdsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->hibira()->chat()->getConversation('');
    }
}
