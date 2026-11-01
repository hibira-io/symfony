<?php

namespace Hibira\SymfonyBundle\Turbo;

use Hibira\SymfonyBundle\Hibira;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Turbo\Broadcaster\IdAccessor;
use Symfony\UX\Turbo\StreamSourceRendererInterface;

/**
 * Renders turbo_stream_from(topics) and <twig:Turbo:Stream:From> (UX Turbo
 * 3.1+) as a <hibira-turbo-stream-source> element. Hibira channels are
 * always signed, so the "private" option changes nothing.
 */
final class StreamSourceRenderer implements StreamSourceRendererInterface
{
    public function __construct(
        private Hibira $hibira,
        private IdAccessor $idAccessor,
        private ?UrlGeneratorInterface $urls = null,
    ) {
    }

    public function render(string|object|array $topics, array $options = []): string
    {
        $values = StreamListenRenderer::values($this->hibira, $this->idAccessor, $this->urls, \is_array($topics) ? array_values($topics) : [$topics]);

        $attributes = [
            'streams' => json_encode($values['streams'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            'data-hibira-key' => $values['key'],
            'data-hibira-host' => $values['host'],
            'data-hibira-port' => (string) $values['port'],
            'data-hibira-tls' => $values['tls'] ? 'true' : 'false',
            'data-hibira-auth-endpoint' => $values['authEndpoint'],
        ];

        $html = '<hibira-turbo-stream-source';
        foreach ($attributes as $name => $value) {
            $html .= sprintf(' %s="%s"', $name, htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $html.'></hibira-turbo-stream-source>';
    }
}
