<?php

namespace Hibira\SymfonyBundle\Exception;

class MissingCredentialsException extends \RuntimeException
{
    private const ENV = [
        'app_id' => 'HIBIRA_APP_ID',
        'key' => 'HIBIRA_APP_KEY',
        'secret' => 'HIBIRA_APP_SECRET',
        'instance_id' => 'HIBIRA_PUSH_INSTANCE_ID',
        'secret_key' => 'HIBIRA_PUSH_SECRET_KEY',
    ];

    /** @param list<string> $names */
    public static function realtime(array $names): self
    {
        $env = array_map(fn (string $name) => self::ENV[$name] ?? $name, $names);

        return new self(sprintf('Hibira is missing %s. Copy them from your app page on https://hibira.io/portal into .env (or set hibira.%s in config/packages/hibira.yaml).', implode(', ', $env), implode(', hibira.', $names)));
    }

    public static function push(string $name): self
    {
        return new self(sprintf('Hibira push notifications are missing %s. Copy it from your app page on https://hibira.io/portal → Push notifications (or set hibira.push.%s).', self::ENV[$name] ?? $name, $name));
    }
}
