<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\UX\Turbo\Attribute\Broadcast;

// Every create, update and remove is rendered with
// templates/broadcast/Message.stream.html.twig and sent to Hibira.
#[ORM\Entity]
#[Broadcast]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 40)]
    public string $author = '';

    #[ORM\Column(length: 280)]
    public string $body = '';

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
