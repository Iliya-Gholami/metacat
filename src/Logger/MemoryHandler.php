<?php

declare(strict_types=1);

namespace MetaCat\Logger;

use MetaCat\Interfaces\Logger\LogHandlerInterface;

/**
 * Memory log handler - stores logs in memory (useful for testing)
 */
class MemoryHandler implements LogHandlerInterface
{
    protected string $level;
    protected array $logs = [];
    protected int $maxLogs;
    
    public function __construct(
        string $level = LogLevel::DEBUG,
        int $maxLogs = 1000
    ) {
        $this->level = LogLevel::isValid($level) ? $level : LogLevel::DEBUG;
        $this->maxLogs = $maxLogs;
    }
    
    public function handle(LogMessage $message): void
    {
        if (!$this->supports($message)) {
            return;
        }
        
        $this->logs[] = $message;
        
        // Keep only the last maxLogs entries
        if (count($this->logs) > $this->maxLogs) {
            array_shift($this->logs);
        }
    }
    
    public function setLevel(string $level): void
    {
        if (LogLevel::isValid($level)) {
            $this->level = $level;
        }
    }
    
    public function getLevel(): string
    {
        return $this->level;
    }
    
    public function supports(LogMessage $message): bool
    {
        return LogLevel::getLevelCode($message->getLevel()) >= LogLevel::getLevelCode($this->level);
    }
    
    public function getLogs(): array
    {
        return $this->logs;
    }
    
    public function clear(): void
    {
        $this->logs = [];
    }
    
    public function count(): int
    {
        return count($this->logs);
    }
    
    public function getLastLog(): ?LogMessage
    {
        return end($this->logs) ?: null;
    }
    
    public function getLogsByLevel(string $level): array
    {
        return array_filter($this->logs, function(LogMessage $message) use ($level) {
            return $message->getLevel() === $level;
        });
    }
}