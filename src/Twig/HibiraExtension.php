<?php

namespace Hibira\SymfonyBundle\Twig;

use Hibira\SymfonyBundle\Hibira;
use Hibira\SymfonyBundle\LiveChat\LiveChat;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ hibira_meta_tags() }} in your layout's <head> gives the hibira() client
 * in JavaScript its key, host and auth endpoint, for your own channels.
 * {{ hibira_live_chat() }} before </body> puts the Live Chat widget on the page.
 */
final class HibiraExtension extends AbstractExtension
{
    public function __construct(
        private Hibira $hibira,
        private ?UrlGeneratorInterface $urls = null,
        private ?LiveChat $liveChat = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('hibira_meta_tags', $this->metaTags(...), ['is_safe' => ['html']]),
            new TwigFunction('hibira_live_chat', $this->liveChat(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array{workspace?: ?string, color?: ?string, lang?: ?string, launcher?: bool, nonce?: ?string} $options
     */
    public function liveChat(array $options = []): string
    {
        return $this->liveChat?->render($options) ?? '';
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
