<?php

namespace Hibira\SymfonyBundle\Turbo;

use Symfony\UX\Turbo\Broadcaster\IdAccessor;

/**
 * Maps Turbo stream topics to Hibira channels.
 *
 * Topics are the ones UX Turbo uses with Mercure, so templates and
 * #[Broadcast] options carry over unchanged: an entity listens on
 * https://symfony.com/ux-turbo/<class>/<id>, a class name on
 * https://symfony.com/ux-turbo/<class>/{id}. Topics hold characters channel
 * names cannot, so each one becomes the private channel
 * private-turbo-<sha256(topic)>, which the browser may only join with the
 * signature turbo_stream_listen() rendered for it.
 */
final class TurboStreams
{
    public const EVENT = 'turbo-stream';

    public const CHANNEL_PREFIX = 'private-turbo-';

    public const TOPIC_PATTERN = 'https://symfony.com/ux-turbo/%s/%s';

    public const REFRESH = '<turbo-stream action="refresh"></turbo-stream>';

    public static function channel(string $topic): string
    {
        return self::CHANNEL_PREFIX.hash('sha256', $topic);
    }

    public static function isTurboChannel(string $channel): bool
    {
        return str_starts_with($channel, self::CHANNEL_PREFIX);
    }

    /**
     * The topic for one entity.
     *
     * @param list<mixed>|string $id
     */
    public static function entityTopic(string $class, array|string $id): string
    {
        return sprintf(self::TOPIC_PATTERN, rawurlencode($class), rawurlencode(implode('-', (array) $id)));
    }

    /**
     * The topic every entity of a class is also sent to, the one Mercure
     * matches with a URI template.
     */
    public static function classTopic(string $class): string
    {
        return sprintf(self::TOPIC_PATTERN, rawurlencode($class), '{id}');
    }

    /**
     * What turbo_stream_listen() was given: a topic string, a class name or
     * an entity.
     */
    public static function resolveTopic(object|string $topic, IdAccessor $idAccessor): string
    {
        if (\is_object($topic)) {
            $id = $idAccessor->getEntityId($topic);
            if (!$id) {
                throw new \LogicException(sprintf('Cannot listen to entity of class "%s" as its id cannot be read. Try running "composer require symfony/property-access".', $topic::class));
            }

            return self::entityTopic(self::entityClass($topic), $id);
        }

        if (!preg_match('/[^a-zA-Z0-9_\x7f-\xff\\\\]/', $topic) && class_exists($topic)) {
            return self::classTopic($topic);
        }

        return $topic;
    }

    /** The class name without Doctrine proxy prefixes. */
    public static function entityClass(object $entity): string
    {
        $class = $entity::class;
        foreach (['Proxies\\__CG__\\', '__CG__\\'] as $marker) {
            if (false !== $pos = strrpos($class, $marker)) {
                return substr($class, $pos + \strlen($marker));
            }
        }

        return $class;
    }
}
