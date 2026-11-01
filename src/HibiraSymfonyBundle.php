<?php

namespace Hibira\SymfonyBundle;

use Hibira\SymfonyBundle\Command\CheckCommand;
use Hibira\SymfonyBundle\Controller\ChannelAuthController;
use Hibira\SymfonyBundle\Controller\PushTokenController;
use Hibira\SymfonyBundle\Controller\WebhookController;
use Hibira\SymfonyBundle\Message\PublishEvent;
use Hibira\SymfonyBundle\MessageHandler\PublishEventHandler;
use Hibira\SymfonyBundle\Turbo\HibiraBroadcaster;
use Hibira\SymfonyBundle\Turbo\StreamListenRenderer;
use Hibira\SymfonyBundle\Turbo\StreamSourceRenderer;
use Hibira\SymfonyBundle\Twig\HibiraExtension;
use Pusher\Pusher;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\Turbo\Broadcaster\BroadcasterInterface;
use Symfony\UX\Turbo\StreamSourceRendererInterface;
use Symfony\UX\Turbo\Twig\TurboStreamListenRendererInterface;
use Twig\Environment;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class HibiraSymfonyBundle extends AbstractBundle
{
    protected string $extensionAlias = 'hibira';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('app_id')->defaultValue('%env(default::HIBIRA_APP_ID)%')->end()
                ->scalarNode('key')->defaultValue('%env(default::HIBIRA_APP_KEY)%')->end()
                ->scalarNode('secret')->defaultValue('%env(default::HIBIRA_APP_SECRET)%')->end()
                ->scalarNode('host')->defaultValue(Hibira::DEFAULT_HOST)->info('The WebSocket host on your app page: ws.hibira.io, or ws-<cluster>.hibira.io')->end()
                ->scalarNode('port')->defaultValue(443)->info('An integer, or an env placeholder such as %env(int:HIBIRA_PORT)%')->end()
                ->scalarNode('scheme')->defaultValue('https')->info('https or http')->end()
                ->arrayNode('turbo')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto')->info('auto: on when symfony/ux-turbo is installed')->end()
                        ->enumNode('oversize')->values(['refresh', 'throw'])->defaultValue('refresh')->info('A stream over the event size limit: send a Turbo page refresh instead, or throw')->end()
                    ->end()
                ->end()
                ->arrayNode('messenger')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto')->info('auto: publish() dispatches PublishEvent when symfony/messenger is installed')->end()
                        ->scalarNode('bus')->defaultValue('messenger.default_bus')->end()
                    ->end()
                ->end()
                ->arrayNode('push')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('instance_id')->defaultValue('%env(default::HIBIRA_PUSH_INSTANCE_ID)%')->end()
                        ->scalarNode('secret_key')->defaultValue('%env(default::HIBIRA_PUSH_SECRET_KEY)%')->end()
                        ->scalarNode('endpoint')->defaultValue(Hibira::DEFAULT_PUSH_ENDPOINT)->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $messenger = $config['messenger']['enabled'] === 'auto'
            ? interface_exists(MessageBusInterface::class) && self::hasBundle($builder, 'FrameworkBundle')
            : (bool) $config['messenger']['enabled'];
        $turbo = $config['turbo']['enabled'] === 'auto'
            ? interface_exists(BroadcasterInterface::class) && self::hasBundle($builder, 'TurboBundle')
            : (bool) $config['turbo']['enabled'];

        $services->set('hibira', Hibira::class)
            ->args([
                [
                    'app_id' => $config['app_id'],
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                    'host' => $config['host'],
                    'port' => $config['port'],
                    'scheme' => $config['scheme'],
                    'push' => $config['push'],
                ],
                $messenger ? service($config['messenger']['bus'])->nullOnInvalid() : null,
                service('logger')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'hibira'])
            ->public();
        $services->alias(Hibira::class, 'hibira')->public();

        $services->set('hibira.pusher', Pusher::class)
            ->factory([service('hibira'), 'pusher']);
        $services->alias(Pusher::class, 'hibira.pusher');

        if ($messenger) {
            $services->set('hibira.publish_event_handler', PublishEventHandler::class)
                ->args([service('hibira')])
                ->tag('messenger.message_handler', ['handles' => PublishEvent::class]);
        }

        $services->set(ChannelAuthController::class)
            ->args([service('hibira'), service('event_dispatcher'), service('security.token_storage')->nullOnInvalid()])
            ->tag('controller.service_arguments')
            ->public();
        $services->set(WebhookController::class)
            ->args([service('hibira'), service('event_dispatcher')])
            ->tag('controller.service_arguments')
            ->public();
        $services->set(PushTokenController::class)
            ->args([service('hibira'), service('security.token_storage')->nullOnInvalid()])
            ->tag('controller.service_arguments')
            ->public();

        $services->set('hibira.command.check', CheckCommand::class)
            ->args([service('hibira'), $turbo, $messenger])
            ->tag('console.command', ['command' => 'hibira:check', 'description' => 'Checks the Hibira credentials and connection']);

        if (class_exists(Environment::class)) {
            $services->set('hibira.twig_extension', HibiraExtension::class)
                ->args([service('hibira'), service('router')->nullOnInvalid()])
                ->tag('twig.extension');
        }

        if ($turbo) {
            $services->set('hibira.turbo.broadcaster', HibiraBroadcaster::class)
                ->args([service('hibira'), $config['turbo']['oversize']])
                ->tag('turbo.broadcaster');

            // turbo_stream_from() and <twig:Turbo:Stream:From>, UX Turbo 3.1+
            if (interface_exists(StreamSourceRendererInterface::class)) {
                $services->set('hibira.turbo.stream_source_renderer', StreamSourceRenderer::class)
                    ->args([service('hibira'), service('turbo.id_accessor'), service('router')->nullOnInvalid()])
                    ->tag('turbo.stream_source_renderer', ['transport' => HibiraBroadcaster::TRANSPORT]);
            }

            // turbo_stream_listen(), deprecated since UX Turbo 3.1
            if (interface_exists(TurboStreamListenRendererInterface::class)) {
                $services->set('hibira.turbo.stream_listen_renderer', StreamListenRenderer::class)
                    ->args([
                        service('hibira'),
                        new Reference('stimulus.helper'),
                        service('turbo.id_accessor'),
                        service('router')->nullOnInvalid(),
                    ])
                    ->tag('turbo.renderer.stream_listen', ['transport' => HibiraBroadcaster::TRANSPORT]);
            }
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Without a Mercure hub, turbo_stream_listen(topic) uses Hibira.
        if (self::hasBundle($builder, 'TurboBundle') && !self::hasBundle($builder, 'MercureBundle')) {
            $builder->prependExtensionConfig('turbo', ['default_transport' => HibiraBroadcaster::TRANSPORT]);
        }

        // The Stimulus controller, for AssetMapper apps.
        if (interface_exists(AssetMapperInterface::class) && self::hasBundle($builder, 'FrameworkBundle')) {
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        $this->getPath().'/assets/dist' => '@hibira-io/symfony-bundle',
                    ],
                ],
            ]);
        }
    }

    private static function hasBundle(ContainerBuilder $builder, string $name): bool
    {
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];

        return \is_array($bundles) && isset($bundles[$name]);
    }
}
