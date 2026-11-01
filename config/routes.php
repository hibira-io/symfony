<?php

use Hibira\SymfonyBundle\Controller\ChannelAuthController;
use Hibira\SymfonyBundle\Controller\PushTokenController;
use Hibira\SymfonyBundle\Controller\WebhookController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Import with a prefix:
 *
 *     # config/routes/hibira.yaml
 *     hibira:
 *         resource: '@HibiraSymfonyBundle/config/routes.php'
 *         prefix: /hibira
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('hibira_auth', '/auth')
        ->controller(ChannelAuthController::class)
        ->methods(['POST']);

    $routes->add('hibira_webhook', '/webhook')
        ->controller(WebhookController::class)
        ->methods(['POST']);

    $routes->add('hibira_push_token', '/push/token')
        ->controller(PushTokenController::class)
        ->methods(['GET']);
};
