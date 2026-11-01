<?php

namespace Hibira\SymfonyBundle\Tests;

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Hibira\SymfonyBundle\Hibira;
use Hibira\SymfonyBundle\Tests\Fixtures\Book;
use Hibira\SymfonyBundle\Tests\Fixtures\EnvKernel;
use Hibira\SymfonyBundle\Tests\Fixtures\TestKernel;
use Hibira\SymfonyBundle\Turbo\TurboStreams;
use PHPUnit\Framework\TestCase;
use Pusher\Pusher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\Broadcaster\BroadcasterInterface;

final class BundleTest extends TestCase
{
    private ?\Symfony\Component\HttpKernel\Kernel $kernel = null;

    /** @var callable|null */
    private $exceptionHandler;

    protected function setUp(): void
    {
        TestKernel::reset();
        $this->exceptionHandler = self::currentExceptionHandler();
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;

        // A debug kernel installs Symfony's exception handler; put PHPUnit's back.
        for ($i = 0; $i < 10 && self::currentExceptionHandler() !== $this->exceptionHandler; ++$i) {
            restore_exception_handler();
        }
    }

    private static function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    private function boot(array $hibira = [], bool $turbo = true): TestKernel
    {
        $kernel = new TestKernel($hibira, $turbo);
        $kernel->boot();

        return $this->kernel = $kernel;
    }

    private function post(string $path, array $form, ?string $user = null): \Symfony\Component\HttpFoundation\Response
    {
        $kernel = $this->kernel ?? $this->boot();
        \assert($kernel instanceof TestKernel);
        $request = Request::create($path, 'POST', $form, [], [], $user !== null ? ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'x'] : []);

        return $kernel->handle($request);
    }

    public function testServicesAreWired(): void
    {
        $container = $this->boot()->getContainer();

        $this->assertInstanceOf(Hibira::class, $container->get('hibira'));
        $this->assertInstanceOf(Hibira::class, $container->get(Hibira::class));
        $this->assertInstanceOf(\Hibira\SymfonyBundle\Turbo\HibiraBroadcaster::class, $container->get('hibira.turbo.broadcaster'));
        $this->assertTrue($container->has('hibira.publish_event_handler'));
        $this->assertSame(['key' => 'app-key', 'host' => 'hibira.test', 'port' => 443, 'tls' => true], $container->get('hibira')->clientConfig());
    }

    public function testCredentialsComeFromEnvByDefault(): void
    {
        $_SERVER['HIBIRA_APP_ID'] = $_ENV['HIBIRA_APP_ID'] = '42';
        $_SERVER['HIBIRA_APP_KEY'] = $_ENV['HIBIRA_APP_KEY'] = 'env-key';
        $_SERVER['HIBIRA_APP_SECRET'] = $_ENV['HIBIRA_APP_SECRET'] = 'env-secret';
        try {
            // Build a kernel whose hibira config sets nothing.
            $kernel = new EnvKernel();
            $kernel->boot();
            $this->kernel = $kernel;
            $this->assertSame('env-key', $kernel->getContainer()->get('hibira')->clientConfig()['key']);
            $this->assertSame([], $kernel->getContainer()->get('hibira')->missingCredentials());
        } finally {
            unset($_SERVER['HIBIRA_APP_ID'], $_ENV['HIBIRA_APP_ID'], $_SERVER['HIBIRA_APP_KEY'], $_ENV['HIBIRA_APP_KEY'], $_SERVER['HIBIRA_APP_SECRET'], $_ENV['HIBIRA_APP_SECRET']);
        }
    }

    public function testTurboStreamListenRendersTheHibiraController(): void
    {
        $container = $this->boot()->getContainer();
        $hibira = $container->get('hibira');

        $html = $container->get('twig')->render('listen.html.twig', ['book' => new Book(7, 'Dune')]);

        $prefix = 'data-hibira-io--symfony-bundle--turbo-stream';
        // Older StimulusBundle versions escape "/" as &#x2F;.
        $decoded = html_entity_decode($html);
        $this->assertStringContainsString('data-controller="hibira-io--symfony-bundle--turbo-stream"', $html);
        $this->assertStringContainsString($prefix.'-key-value="app-key"', $decoded);
        $this->assertStringContainsString($prefix.'-host-value="hibira.test"', $decoded);
        $this->assertStringContainsString($prefix.'-auth-endpoint-value="/hibira/auth"', $decoded);

        foreach ([
            'by-topic' => 'chat',
            'by-entity' => TurboStreams::entityTopic(Book::class, ['7']),
            'by-class' => TurboStreams::classTopic(Book::class),
        ] as $id => $topic) {
            preg_match('/id="'.$id.'"[^>]*'.$prefix.'-streams-value="([^"]+)"/', $html, $m);
            $this->assertNotEmpty($m, $id);
            $streams = json_decode(html_entity_decode($m[1]), true);
            $channel = TurboStreams::channel($topic);
            $this->assertSame([['channel' => $channel, 'signature' => $hibira->sign($channel)]], $streams, $id);
        }

        $this->assertStringContainsString('<meta name="hibira-key" content="app-key">', $html);
        $this->assertStringContainsString('<meta name="hibira-auth-endpoint" content="/hibira/auth">', $html);
    }

    public function testTurboStreamFromRendersTheHibiraElement(): void
    {
        if (!interface_exists(\Symfony\UX\Turbo\StreamSourceRendererInterface::class)) {
            $this->markTestSkipped('turbo_stream_from() needs UX Turbo 3.1+');
        }

        $container = $this->boot()->getContainer();
        $hibira = $container->get('hibira');
        $html = $container->get('twig')->render('from.html.twig', ['book' => new Book(7, 'Dune')]);

        $this->assertStringContainsString('data-hibira-key="app-key"', $html);
        $this->assertStringContainsString('data-hibira-auth-endpoint="/hibira/auth"', $html);

        preg_match_all('/<hibira-turbo-stream-source streams="([^"]+)"/', $html, $m);
        $this->assertCount(2, $m[1]);
        $channels = fn (string $json) => array_column(json_decode(html_entity_decode($json), true), 'channel');
        $this->assertSame([TurboStreams::channel(TurboStreams::entityTopic(Book::class, ['7']))], $channels($m[1][0]));
        $this->assertSame([TurboStreams::channel('chat'), TurboStreams::channel(TurboStreams::classTopic(Book::class))], $channels($m[1][1]));

        $streams = json_decode(html_entity_decode($m[1][0]), true);
        $this->assertTrue($hibira->verify($streams[0]['channel'], $streams[0]['signature']));
    }

    public function testBroadcastSendsTheRenderedStreamThroughMessenger(): void
    {
        $container = $this->boot()->getContainer();
        TestKernel::$responses->append(new GuzzleResponse(200, [], '{}'));

        $container->get('turbo.broadcaster.imux')->broadcast(new Book(7, 'Dune'), 'update', ['template' => 'book.stream.html.twig']);

        $requests = TestKernel::requests();
        $this->assertCount(1, $requests);
        $body = $requests[0]['body'];
        $this->assertSame([
            TurboStreams::channel(TurboStreams::entityTopic(Book::class, ['7'])),
            TurboStreams::channel(TurboStreams::classTopic(Book::class)),
        ], $body['channels']);
        $this->assertSame('turbo-stream', $body['name']);
        $this->assertSame('<turbo-stream action="replace" target="book_7"><template><li id="book_7">Dune</li></template></turbo-stream>', json_decode($body['data']));
    }

    public function testBroadcastToExplicitTopicsAndSkipsOtherTransports(): void
    {
        $container = $this->boot()->getContainer();
        TestKernel::$responses->append(new GuzzleResponse(200, [], '{}'));
        $broadcaster = $container->get('turbo.broadcaster.imux');

        $broadcaster->broadcast(new Book(7, 'Dune'), 'create', ['template' => 'book.stream.html.twig', 'topics' => ['shelf', '@="book-" ~ entity.id'], 'transports' => ['hibira']]);
        $broadcaster->broadcast(new Book(8, 'Emma'), 'create', ['template' => 'book.stream.html.twig', 'transports' => ['mercure']]);

        $requests = TestKernel::requests();
        $this->assertCount(1, $requests);
        $this->assertSame([TurboStreams::channel('shelf'), TurboStreams::channel('book-7')], $requests[0]['body']['channels']);
    }

    public function testOversizedStreamFallsBackToARefresh(): void
    {
        $container = $this->boot()->getContainer();
        TestKernel::$responses->append(new GuzzleResponse(413, [], 'Payload Too Large'), new GuzzleResponse(200, [], '{}'));

        $container->get('turbo.broadcaster.imux')->broadcast(new Book(7, str_repeat('x', 5000)), 'update', ['template' => 'book.stream.html.twig']);

        $requests = TestKernel::requests();
        $this->assertCount(2, $requests);
        $this->assertSame(TurboStreams::REFRESH, json_decode($requests[1]['body']['data']));
    }

    public function testTurboChannelsNeedTheRenderedSignature(): void
    {
        $kernel = $this->boot();
        $channel = TurboStreams::channel('chat');
        $signature = $kernel->getContainer()->get('hibira')->sign($channel);

        $ok = $this->post('/hibira/auth', ['socket_id' => '1.2', 'channel_name' => $channel, 'signature' => $signature]);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('app-key:'.hash_hmac('sha256', '1.2:'.$channel, 'app-secret'), json_decode($ok->getContent(), true)['auth']);

        $this->assertSame(403, $this->post('/hibira/auth', ['socket_id' => '1.2', 'channel_name' => $channel, 'signature' => 'nope'])->getStatusCode());
        $this->assertSame(403, $this->post('/hibira/auth', ['socket_id' => '1.2', 'channel_name' => TurboStreams::channel('other'), 'signature' => $signature])->getStatusCode());
    }

    public function testOtherChannelsAskTheListeners(): void
    {
        $this->boot();

        $this->assertSame(200, $this->post('/hibira/auth', ['socket_id' => '1.2', 'channel_name' => 'private-open'])->getStatusCode());
        $this->assertSame(403, $this->post('/hibira/auth', ['socket_id' => '1.2', 'channel_name' => 'private-closed'])->getStatusCode());
        $this->assertSame(400, $this->post('/hibira/auth', ['socket_id' => 'x', 'channel_name' => 'private-open'])->getStatusCode());
        $this->assertSame(400, $this->post('/hibira/auth', ['socket_id' => '1.2', 'channel_name' => 'public'])->getStatusCode());
    }

    public function testPresenceChannelsUseTheSignedInUser(): void
    {
        $this->boot();

        $this->assertSame(403, $this->post('/hibira/auth', ['socket_id' => '1.2', 'channel_name' => 'presence-room'])->getStatusCode());
        $this->assertSame(403, $this->post('/hibira/auth', ['socket_id' => '1.2', 'channel_name' => 'presence-guest'])->getStatusCode());

        $response = $this->post('/hibira/auth', ['socket_id' => '1.2', 'channel_name' => 'presence-room'], 'ada');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"user_id":"ada","user_info":{"name":"Ada"}}', json_decode($response->getContent(), true)['channel_data']);
    }

    public function testWebhooksAreVerifiedAndDispatched(): void
    {
        $kernel = $this->boot();
        $body = json_encode(['time_ms' => 123, 'events' => [
            ['name' => 'channel_occupied', 'channel' => 'private-a'],
            ['name' => 'member_added', 'channel' => 'presence-b', 'user_id' => 'ada'],
            'junk',
        ]]);

        $request = Request::create('/hibira/webhook', 'POST', [], [], [], [
            'HTTP_X_PUSHER_KEY' => 'app-key',
            'HTTP_X_PUSHER_SIGNATURE' => hash_hmac('sha256', $body, 'app-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
        $this->assertSame(200, $kernel->handle($request)->getStatusCode());

        $webhooks = $kernel->getContainer()->get('test.listeners')->webhooks;
        $this->assertCount(2, $webhooks);
        $this->assertSame('member_added', $webhooks[1]->name);
        $this->assertSame('presence-b', $webhooks[1]->channel);
        $this->assertSame('ada', $webhooks[1]->payload['user_id']);
        $this->assertSame(123, $webhooks[1]->timeMs);

        $bad = Request::create('/hibira/webhook', 'POST', [], [], [], ['HTTP_X_PUSHER_KEY' => 'app-key', 'HTTP_X_PUSHER_SIGNATURE' => 'bad'], $body);
        $this->assertSame(401, $kernel->handle($bad)->getStatusCode());
    }

    public function testPushTokenRoute(): void
    {
        $kernel = $this->boot(['push' => ['instance_id' => 'inst', 'secret_key' => str_repeat('a', 64)]]);
        $get = fn (string $q, array $server = ['PHP_AUTH_USER' => 'ada', 'PHP_AUTH_PW' => 'x']) => $kernel->handle(Request::create('/hibira/push/token?'.$q, 'GET', [], [], [], $server));

        $this->assertSame(401, $get('user_id=ada', [])->getStatusCode());
        $this->assertSame(401, $get('user_id=bob')->getStatusCode());
        $ok = $get('user_id=ada');
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertArrayHasKey('token', json_decode($ok->getContent(), true));
    }

    public function testPushTokenRouteIsOffWithoutPush(): void
    {
        $kernel = $this->boot();
        $this->assertSame(404, $kernel->handle(Request::create('/hibira/push/token?user_id=ada'))->getStatusCode());
    }

    public function testWorksWithoutTurbo(): void
    {
        $container = $this->boot([], false)->getContainer();

        $this->assertTrue($container->has('hibira'));
        $this->assertFalse($container->has('hibira.turbo.broadcaster'));
        $this->assertInstanceOf(Pusher::class, $container->get('hibira')->pusher());
    }

    public function testCheckCommand(): void
    {
        $kernel = $this->boot();
        TestKernel::$responses->append(new GuzzleResponse(200, [], '{"channels":{"a":{},"b":{}}}'));

        $tester = new CommandTester((new Application($kernel))->find('hibira:check'));
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('2 occupied channel(s)', $tester->getDisplay());
        $this->assertStringContainsString('on (transport "hibira")', $tester->getDisplay());
    }

    public function testCheckCommandFailsWithoutCredentials(): void
    {
        $kernel = $this->boot(['key' => '', 'secret' => '']);

        $tester = new CommandTester((new Application($kernel))->find('hibira:check'));
        $this->assertSame(1, $tester->execute(['--offline' => true]));
        $this->assertStringContainsString('HIBIRA_APP_KEY', $tester->getDisplay());
    }
}
