<?php

namespace Hibira\SymfonyBundle\Twig;

use Hibira\SymfonyBundle\Hibira;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ hibira_meta_tags() }} in your layout's <head> gives the hibira() client
 * in JavaScript its key, host and auth endpoint, for your own channels.
 */
final class HibiraExtension extends AbstractExtension
{
    public function __construct(
        private Hibira $hibira,
        private ?UrlGeneratorInterface $urls = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('hibira_meta_tags', $this->metaTags(...), ['is_safe' => ['html']]),
        ];
    }

    public function metaTags(): string
    {
        $client = $this->hibira->clientConfig();
        $tags = [
            'hibira-key' => $client['key'],
            'hibira-host' => $client['host'],
            'hibira-port' => (string) $client['port'],
            'hibira-tls' => $client['tls'] ? 'true' : 'false',
            'hibira-auth-endpoint' => $this->authEndpoint(),
        ];

        $html = '';
        foreach ($tags as $name => $content) {
            $html .= sprintf('<meta name="%s" content="%s">', $name, htmlspecialchars($content, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $html;
    }

    private function authEndpoint(): string
    {
        try {
            return $this->urls?->generate('hibira_auth') ?? '/hibira/auth';
        } catch (RouteNotFoundException) {
            return '/hibira/auth';
        }
    }
}
