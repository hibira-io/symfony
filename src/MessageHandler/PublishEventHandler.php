<?php

namespace Hibira\SymfonyBundle\MessageHandler;

use Hibira\SymfonyBundle\Hibira;
use Hibira\SymfonyBundle\Message\PublishEvent;

final class PublishEventHandler
{
    public function __construct(private Hibira $hibira)
    {
    }

    public function __invoke(PublishEvent $message): void
    {
        $this->hibira->send($message);
    }
}
