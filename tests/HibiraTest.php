<?php

namespace Hibira\SymfonyBundle\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Hibira\SymfonyBundle\Exception\MissingCredentialsException;
use Hibira\SymfonyBundle\Exception\PayloadTooLargeException;
use Hibira\SymfonyBundle\Hibira;
use PHPUnit\Framework\TestCase;

final class HibiraTest extends TestCase
{
    private array $history = [];

    private MockHandler $responses;

    private function hibira(array $config = []): Hibira
    {
        $this->responses = new MockHandler();
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));

        return new Hibira($config + [
            'app_id' => '1',
            'key' => 'app-key',
            'secret' => 'app-secret',
            'host' => 'hibira.test',
            'port' => 443,
            'scheme' => 'https',
            'push' => ['instance_id' => null, 'secret_key' => null, 'endpoint' => Hibira::DEFAULT_PUSH_ENDPOINT],
        ], null, null, new Client(['handler' => $stack]));
    }

    private function bodies(): array
    {
        return array_map(fn ($entry) => json_decode((string) $entry['request']->getBody(), true), $this->history);
    }

    public function testTriggerSendsToTheConfiguredHost(): void
    {
        $hibira = $this->hibira();
        $this->responses->append(new Response(200, [], '{}'));

        $hibira->trigger('orders', 'shipped', ['id' => 7], '1.2');

        $request = $this->history[0]['request'];
        $this->assertSame('https://hibira.test/apps/1/events', preg_replace('/\?.*/', '', (string) $request->getUri()));
        $body = $this->bodies()[0];
        $this->assertSame(['orders'], $body['channels'] ?? [$body['channel']]);
        $this->assertSame('shipped', $body['name']);
        $this->assertSame('{"id":7}', $body['data']);
        $this->assertSame('1.2', $body['socket_id']);
    }

    public function testPublishWithoutMessengerSendsRightAway(): void
    {
        $hibira = $this->hibira();
        $this->responses->append(new Response(200, [], '{}'));

        $hibira->publish(['a', 'b', 'a'], 'ping', 'hello');

        $this->assertSame(['a', 'b'], $this->bodies()[0]['channels']);
        $this->assertSame('"hello"', $this->bodies()[0]['data']);
    }

    public function testTurboStreamGoesToTheTopicChannels(): void
    {
        $hibira = $this->hibira();
        $this->responses->append(new Response(200, [], '{}'));

        $hibira->turboStream(['chat', 'room-1'], '<turbo-stream action="append" target="x"></turbo-stream>');

        $body = $this->bodies()[0];
        $this->assertSame([\Hibira\SymfonyBundle\Turbo\TurboStreams::channel('chat'), \Hibira\SymfonyBundle\Turbo\TurboStreams::channel('room-1')], $body['channels']);
        $this->assertSame('turbo-stream', $body['name']);
    }

    public function testMoreThanOneHundredChannelsAreSplit(): void
    {
        $hibira = $this->hibira();
        $this->responses->append(new Response(200, [], '{}'), new Response(200, [], '{}'));

        $hibira->trigger(array_map(fn ($i) => "c$i", range(1, 150)), 'ping', 1);

        $this->assertCount(100, $this->bodies()[0]['channels']);
        $this->assertCount(50, $this->bodies()[1]['channels']);
    }

    public function testOversizeSendsTheFallback(): void
    {
        $hibira = $this->hibira();
        $this->responses->append(new Response(413, [], 'Payload Too Large'), new Response(200, [], '{}'));

        $hibira->publish('c', 'turbo-stream', str_repeat('x', 5000), null, 'refresh');

        $this->assertCount(2, $this->history);
        $this->assertSame('"refresh"', $this->bodies()[1]['data']);
    }

    public function testOversizeWithoutFallbackThrows(): void
    {
        $hibira = $this->hibira();
        $this->responses->append(new Response(413, [], 'Payload Too Large'));

        $this->expectException(PayloadTooLargeException::class);
        $hibira->trigger('c', 'big', str_repeat('x', 5000));
    }

    public function testMissingCredentialsNameTheEnvVars(): void
    {
        $hibira = $this->hibira(['key' => '', 'secret' => null]);
        $this->assertSame(['key', 'secret'], $hibira->missingCredentials());

        $this->expectException(MissingCredentialsException::class);
        $this->expectExceptionMessage('HIBIRA_APP_KEY, HIBIRA_APP_SECRET');
        $hibira->pusher();
    }

    public function testChannelAuthorization(): void
    {
        $hibira = $this->hibira();
        $auth = json_decode($hibira->authorizeChannel('private-a', '1.2'), true);
        $this->assertSame('app-key:'.hash_hmac('sha256', '1.2:private-a', 'app-secret'), $auth['auth']);

        $presence = json_decode($hibira->authorizePresenceChannel('presence-a', '1.2', 'u1', ['name' => 'Ada']), true);
        $this->assertSame('{"user_id":"u1","user_info":{"name":"Ada"}}', $presence['channel_data']);
    }

    public function testSignatures(): void
    {
        $hibira = $this->hibira();
        $signature = $hibira->sign('private-turbo-abc');

        $this->assertTrue($hibira->verify('private-turbo-abc', $signature));
        $this->assertFalse($hibira->verify('private-turbo-abd', $signature));
        $this->assertFalse($this->hibira(['secret' => 'other'])->verify('private-turbo-abc', $signature));
    }

    public function testWebhookSignatures(): void
    {
        $hibira = $this->hibira();
        $body = '{"time_ms":1,"events":[]}';
        $good = hash_hmac('sha256', $body, 'app-secret');

        $this->assertTrue($hibira->verifyWebhook($body, 'app-key', [$good, null]));
        $this->assertTrue($hibira->verifyWebhook($body, 'app-key', ['bad', strtoupper($good)]));
        $this->assertFalse($hibira->verifyWebhook($body, 'other-key', [$good]));
        $this->assertFalse($hibira->verifyWebhook($body.' ', 'app-key', [$good]));
    }

    public function testPushNeedsCredentials(): void
    {
        $hibira = $this->hibira();
        $this->assertFalse($hibira->hasPush());

        $this->expectException(MissingCredentialsException::class);
        $this->expectExceptionMessage('HIBIRA_PUSH_INSTANCE_ID');
        $hibira->push();
    }

    public function testPushUsesTheHibiraEndpoint(): void
    {
        $hibira = $this->hibira(['push' => ['instance_id' => 'inst', 'secret_key' => str_repeat('a', 64), 'endpoint' => 'https://push.hibira.test/']]);

        $this->assertTrue($hibira->hasPush());
        $token = $hibira->push()->generateToken('ada');
        $this->assertArrayHasKey('token', $token);
        $claims = json_decode(base64_decode(strtr(explode('.', $token['token'])[1], '-_', '+/')), true);
        $this->assertSame('ada', $claims['sub']);
    }
}
