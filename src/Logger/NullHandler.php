<?php

declare(strict_types=1);

namespace MetaCat\Logger;

use MetaCat\Interfaces\Logger\LogHandlerInterface;

/**
 * Null logger - does nothing (useful for testing or disabling logging)
 */
class NullHandler implements LogHandlerInterface
{
    public function handle(LogMessage $message): void
    {
        // Do nothing
    }
    
    public function setLevel(string $level): void
    {
        // Do nothing
    }
    
    public function getLevel(): string
    {
        return LogLevel::DEBUG;
    }
    
    public function supports(LogMessage $message): bool
    {
        return true;
    }
}