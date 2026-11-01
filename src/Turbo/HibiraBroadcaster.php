<?php

namespace Hibira\SymfonyBundle\Turbo;

use Hibira\SymfonyBundle\Hibira;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\UX\Turbo\Broadcaster\BroadcasterInterface;

/**
 * Sends #[Broadcast] entity changes to Hibira, in place of the Mercure
 * broadcaster. UX Turbo renders the stream template first, so this only
 * picks the channels and sends the HTML.
 */
final class HibiraBroadcaster implements BroadcasterInterface
{
    public const TRANSPORT = 'hibira';

    private ?ExpressionLanguage $expressionLanguage;

    /**
     * @param 'refresh'|'throw' $oversize what to do when a stream is over the app's event size limit
     */
    public function __construct(
        private Hibira $hibira,
        private string $oversize = 'refresh',
        private string $name = self::TRANSPORT,
    ) {
        $this->expressionLanguage = class_exists(ExpressionLanguage::class) ? new ExpressionLanguage() : null;
    }

    public function broadcast(object $entity, string $action, array $options): void
    {
        if (isset($options['transports']) && !\in_array($this->name, (array) $options['transports'], true)) {
            return;
        }

        $class = TurboStreams::entityClass($entity);

        if (!isset($options['rendered_action'])) {
            throw new \InvalidArgumentException(sprintf('Cannot broadcast entity of class "%s" as option "rendered_action" is missing.', $class));
        }

        $topics = [];
        foreach ((array) ($options['topics'] ?? []) as $topic) {
            if (\is_string($topic) && str_starts_with($topic, '@=')) {
                if ($this->expressionLanguage === null) {
                    throw new \LogicException('The "@=" expression syntax cannot be used without the Expression Language component. Try running "composer require symfony/expression-language".');
                }
                $topic = $this->expressionLanguage->evaluate(substr($topic, 2), ['entity' => $entity]);
            }
            $topics[] = (string) $topic;
        }

        if ($topics === []) {
            if (!isset($options['id'])) {
                throw new \InvalidArgumentException(sprintf('Cannot broadcast entity of class "%s": either option "topics" or "id" is missing, or the PropertyAccess component is not installed. Try running "composer require property-access".', $class));
            }

            $topics = [
                TurboStreams::entityTopic($class, $options['id']),
                TurboStreams::classTopic($class),
            ];
        }

        $content = (string) $options['rendered_action'];
        $fallback = $this->oversize === 'refresh' && !str_contains($content, 'action="refresh"') ? TurboStreams::REFRESH : null;

        $this->hibira->publish(array_map(TurboStreams::channel(...), $topics), TurboStreams::EVENT, $content, null, $fallback);
    }
}
