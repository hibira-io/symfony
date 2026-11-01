<?php

namespace Hibira\SymfonyBundle\Turbo;

use Hibira\SymfonyBundle\Hibira;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\StimulusBundle\Helper\StimulusHelper;
use Symfony\UX\Turbo\Bridge\Mercure\TopicSet;
use Symfony\UX\Turbo\Broadcaster\IdAccessor;
use Symfony\UX\Turbo\Twig\TurboStreamListenRendererInterface;
use Twig\Environment;

/**
 * Renders turbo_stream_listen(topic, 'hibira') (UX Turbo 2, and 3 until it
 * is removed): the attributes of the Stimulus controller that subscribes to
 * the topics' Hibira channels.
 */
final class StreamListenRenderer implements TurboStreamListenRendererInterface
{
    public const CONTROLLER = '@hibira-io/symfony-bundle/turbo-stream';

    public function __construct(
        private Hibira $hibira,
        private StimulusHelper $stimulus,
        private IdAccessor $idAccessor,
        private ?UrlGeneratorInterface $urls = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function renderTurboStreamListen(Environment $env, $topic, array $options = []): string
    {
        $topics = match (true) {
            $topic instanceof TopicSet => $topic->getTopics(),
            \is_array($topic) => $topic,
            default => [$topic],
        };

        $attributes = $this->stimulus->createStimulusAttributes();
        $attributes->addController(self::CONTROLLER, self::values($this->hibira, $this->idAccessor, $this->urls, $topics));

        return (string) $attributes;
    }

    /**
     * What the browser needs to subscribe: connection settings and, for each
     * topic, its channel and the signature the auth endpoint checks.
     *
     * @param array<object|string> $topics
     *
     * @return array{key: string, host: string, port: int, tls: bool, authEndpoint: string, streams: list<array{channel: string, signature: string}>}
     *
     * @internal
     */
    public static function values(Hibira $hibira, IdAccessor $idAccessor, ?UrlGeneratorInterface $urls, array $topics): array
    {
        $streams = [];
        foreach ($topics as $topic) {
            $channel = TurboStreams::channel(TurboStreams::resolveTopic($topic, $idAccessor));
            $streams[] = ['channel' => $channel, 'signature' => $hibira->sign($channel)];
        }

        $authEndpoint = '/hibira/auth';
        try {
            $authEndpoint = $urls?->generate('hibira_auth') ?? $authEndpoint;
        } catch (RouteNotFoundException) {
        }

        return $hibira->clientConfig() + ['authEndpoint' => $authEndpoint, 'streams' => $streams];
    }
}
