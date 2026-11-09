<?php

namespace Hibira\SymfonyBundle\Tests\Fixtures;

use Hibira\SymfonyBundle\WaitingRoom\WaitingRoom;
use Hibira\SymfonyBundle\WaitingRoom\WaitingRoomPass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DropController
{
    #[WaitingRoom('presale')]
    public function show(Request $request): Response
    {
        $pass = $request->attributes->get('hibira_pass');
        \assert($pass instanceof WaitingRoomPass);

        return new Response('in #'.$pass->number);
    }

    public function open(Request $request): Response
    {
        $pass = $request->attributes->get('hibira_pass');

        return new Response($pass instanceof WaitingRoomPass ? 'vip #'.$pass->number : 'open');
    }
}
