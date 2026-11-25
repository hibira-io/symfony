<?php

namespace Hibira\SymfonyBundle\Exception;

/**
 * The Approvals API answered with an error: getCode() is the HTTP status and
 * the message is the API's.
 */
final class ApprovalException extends \RuntimeException
{
    public function __construct(int $status, string $message, private readonly string $body = '')
    {
        parent::__construct($message, $status);
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
