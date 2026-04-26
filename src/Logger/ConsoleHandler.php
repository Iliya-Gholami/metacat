<?php

declare(strict_types=1);

namespace MetaCat\Logger;

use MetaCat\Interfaces\Logger\LogHandlerInterface;

/**
 * Console log handler - writes logs to console/STDOUT
 */
class ConsoleHandler implements LogHandlerInterface
{
    protected string $level;
    protected bool $useColors;
    protected array $levelColors = [
        LogLevel::DEBUG     => "\033[36m",     // Cyan
        LogLevel::INFO      => "\033[32m",     // Green
        LogLevel::NOTICE    => "\033[34m",     // Blue
        LogLevel::WARNING   => "\033[33m",     // Yellow
        LogLevel::ERROR     => "\033[31m",     // Red
        LogLevel::CRITICAL  => "\033[35m",     // Magenta
        LogLevel::ALERT     => "\033[45m",     // Magenta background
        LogLevel::EMERGENCY => "\033[41m",     // Red background
    ];
    protected string $resetColor = "\033[0m";
    
    public function __construct(
        string $level = LogLevel::INFO,
        bool $useColors = true
    ) {
        $this->level = LogLevel::isValid($level) ? $level : LogLevel::INFO;
        $this->useColors = $useColors && $this->isCli();
    }
    
    protected function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }
    
    public function handle(LogMessage $message): void
    {
        if (!$this->supports($message)) {
            return;
        }
        
        $output = $this->formatMessage($message);
        
        if ($message->getLevel() === LogLevel::ERROR ||
            $message->getLevel() === LogLevel::CRITICAL ||
            $message->getLevel() === LogLevel::EMERGENCY ||
            $message->getLevel() === LogLevel::ALERT) {
            fwrite(STDERR, $output . PHP_EOL);
        } else {
            fwrite(STDOUT, $output . PHP_EOL);
        }
    }
    
    protected function formatMessage(LogMessage $message): string
    {
        $datetime = $message->getDateTime()->format('Y-m-d H:i:s');
        $level = strtoupper($message->getLevel());
        $channel = $message->getChannel();
        $text = $message->format();
        
        if ($this->useColors) {
            $color = $this->levelColors[$message->getLevel()] ?? "\033[37m"; // Default white
            return sprintf(
                '%s[%s] %s.%s%s: %s',
                $color,
                $datetime,
                $channel,
                $level,
                $this->resetColor,
                $message->interpolateMessage()
            );
        }
        
        return sprintf('[%s] %s.%s: %s', $datetime, $channel, $level, $text);
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
}