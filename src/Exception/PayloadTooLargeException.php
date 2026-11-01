<?php

namespace Hibira\SymfonyBundle\Exception;

/**
 * Hibira refused an event bigger than the app's per-event size limit
 * (4 KB on Free, 10 KB on Hobby and Starter).
 */
class PayloadTooLargeException extends \RuntimeException
{
}
