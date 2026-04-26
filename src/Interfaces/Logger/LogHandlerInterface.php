<?php

declare(strict_types=1);

namespace MetaCat\Interfaces\Logger;

use MetaCat\Logger\LogMessage;

/**
 * Log handler interface
 */
interface LogHandlerInterface
{
    public function handle(LogMessage $message): void;
    public function setLevel(string $level): void;
    public function getLevel(): string;
    public function supports(LogMessage $message): bool;
}