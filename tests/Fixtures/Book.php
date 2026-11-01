<?php

namespace Hibira\SymfonyBundle\Tests\Fixtures;

final class Book
{
    public function __construct(public int $id, public string $title)
    {
    }
}
