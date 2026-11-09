<?php

namespace Hibira\SymfonyBundle\Tests\Fixtures;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Hibira\SymfonyBundle\HibiraSymfonyBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfony\UX\Turbo\TurboBundle;

/**
 * An app with FrameworkBundle, Twig, Security, Messenger and UX Turbo. Every
 * Channels API call goes to an in-memory Guzzle handler; requests() lists
 * them.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    public static array $history = [];

    public static ?MockHandler $responses = null;

    /**
     * @param array<string, mixed> $hibira
     */
    public function __construct(private array $hibira = [], private bool $turbo = true)
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new SecurityBundle();
        if ($this->turbo) {
            yield new StimulusBundle();
            yield new TurboBundle();
        }
        yield new HibiraSymfonyBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/hibira-symfony-tests/'.md5(serialize([$this->hibira, $this->turbo, Kernel::VERSION])).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/hibira-symfony-tests/log';
    }

    public static function httpClient(): Client
    {
        self::$responses ??= new MockHandler();
        $stack = HandlerStack::create(self::$responses);
        $stack->push(Middleware::history(self::$history));

        return new Client(['handler' => $stack]);
    }

    /** @return list<array{url: string, body: array<string, mixed>}> */
    public static function requests(): array
    {
        return array_map(fn (array $entry) => [
            'url' => (string) $entry['request']->getUri(),
            'body' => json_decode((string) $entry['request']->getBody(), true) ?? [],
        ], self::$history);
    }

    public static function reset(): void
    {
        self::$history = [];
        self::$responses = new MockHandler();
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'router' => ['utf8' => true],
            'messenger' => ['enabled' => true],
            'property_access' => ['enabled' => true],
        ]);
        $container->loadFromExtension('twig', [
            'default_path' => __DIR__.'/templates',
        ]);
        $container->loadFromExtension('security', [
            'providers' => ['users' => ['memory' => ['users' => ['ada' => ['password' => 'x', 'roles' => ['ROLE_USER']]]]]],
            'password_hashers' => [\Symfony\Component\Security\Core\User\InMemoryUser::class => 'plaintext'],
            'firewalls' => ['main' => ['stateless' => true, 'provider' => 'users', 'http_basic' => null]],
        ]);
        $container->loadFromExtension('hibira', $this->hibira + [
            'app_id' => '1',
            'key' => 'app-key',
            'secret' => 'app-secret',
            'host' => 'hibira.test',
        ]);

        $container->register('test.listeners', TestListeners::class)
            ->setPublic(true)
            ->addTag('kernel.event_listener', ['event' => \Hibira\SymfonyBundle\Event\ChannelAuthorizationEvent::class, 'method' => 'onAuthorize'])
            ->addTag('kernel.event_listener', ['event' => \Hibira\SymfonyBundle\Event\WebhookReceivedEvent::class, 'method' => 'onWebhook']);

        $container->register('logger', \Psr\Log\NullLogger::class);
        $container->register(DropController::class)->setPublic(true)->addTag('controller.service_arguments');
        $container->register('test.http_client', Client::class)->setFactory([self::class, 'httpClient']);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition('hibira')->setArgument(3, new Reference('test.http_client'));
                foreach (['hibira.turbo.broadcaster', 'hibira.publish_event_handler', 'turbo.broadcaster.imux', 'messenger.default_bus', 'twig', 'security.token_storage'] as $id) {
                    if ($container->has($id)) {
                        $container->findDefinition($id)->setPublic(true);
                    }
                }
            }
        });
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@HibiraSymfonyBundle/config/routes.php')->prefix('/hibira');
        $routes->add('drop', '/drop/{id}')->controller([DropController::class, 'show'])->methods(['GET', 'POST']);
        $routes->add('vip', '/vip/{id}')->controller([DropController::class, 'open'])->methods(['GET', 'POST']);
        $routes->add('open', '/open')->controller([DropController::class, 'open']);
    }
}
