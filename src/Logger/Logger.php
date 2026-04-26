<?php

declare(strict_types=1);

namespace MetaCat\Logger;

use MetaCat\Interfaces\Logger\LogHandlerInterface;

/**
 * Main logger class
 */
class Logger
{
    protected string $channel;
    protected array $handlers = [];
    protected array $processors = [];
    protected static array $channels = [];
    
    public function __construct(string $channel = 'app')
    {
        $this->channel = $channel;
    }
    
    /**
     * Get a logger instance for a specific channel
     */
    public static function channel(string $channel): self
    {
        if (!isset(self::$channels[$channel])) {
            self::$channels[$channel] = new self($channel);
        }
        
        return self::$channels[$channel];
    }
    
    /**
     * Get the default logger
     */
    public static function getDefault(): self
    {
        return self::channel('app');
    }
    
    /**
     * Add a log handler
     */
    public function addHandler(LogHandlerInterface $handler): self
    {
        $this->handlers[] = $handler;
        return $this;
    }
    
    /**
     * Add a processor (modify log messages before handling)
     */
    public function addProcessor(callable $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }
    
    /**
     * Log a message
     */
    public function log(string $level, string $message, array $context = [], array $extra = []): void
    {
        if (!LogLevel::isValid($level)) {
            throw new \InvalidArgumentException(sprintf('Invalid log level: %s', $level));
        }
        
        $logMessage = new LogMessage($message, $context, $level, $this->channel, $extra);
        
        // Apply processors
        foreach ($this->processors as $processor) {
            $logMessage = $processor($logMessage);
            if (!$logMessage instanceof LogMessage) {
                throw new \RuntimeException('Processor must return a LogMessage instance');
            }
        }
        
        // Pass to handlers
        foreach ($this->handlers as $handler) {
            if ($handler->supports($logMessage)) {
                $handler->handle($logMessage);
            }
        }
    }
    
    /**
     * System is unusable
     */
    public function emergency(string $message, array $context = [], array $extra = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context, $extra);
    }
    
    /**
     * Action must be taken immediately
     */
    public function alert(string $message, array $context = [], array $extra = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context, $extra);
    }
    
    /**
     * Critical conditions
     */
    public function critical(string $message, array $context = [], array $extra = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context, $extra);
    }
    
    /**
     * Runtime errors that do not require immediate action
     */
    public function error(string $message, array $context = [], array $extra = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context, $extra);
    }
    
    /**
     * Exceptional occurrences that are not errors
     */
    public function warning(string $message, array $context = [], array $extra = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context, $extra);
    }
    
    /**
     * Normal but significant events
     */
    public function notice(string $message, array $context = [], array $extra = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context, $extra);
    }
    
    /**
     * Interesting events
     */
    public function info(string $message, array $context = [], array $extra = []): void
    {
        $this->log(LogLevel::INFO, $message, $context, $extra);
    }
    
    /**
     * Detailed debug information
     */
    public function debug(string $message, array $context = [], array $extra = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context, $extra);
    }
    
    /**
     * Get all handlers
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }
    
    /**
     * Get the channel name
     */
    public function getChannel(): string
    {
        return $this->channel;
    }
    
    /**
     * Create a default logger with file and console handlers
     */
    public static function createDefault(?string $logFile = null): self
    {
        $logger = new self('app');
        
        // Add console handler for CLI or development
        if (PHP_SAPI === 'cli' || getenv('APP_ENV') === 'development') {
            $consoleHandler = new ConsoleHandler(LogLevel::INFO);
            $logger->addHandler($consoleHandler);
        }
        
        // Add file handler if log file specified
        if ($logFile !== null) {
            $fileHandler = new FileHandler($logFile, LogLevel::DEBUG);
            $logger->addHandler($fileHandler);
        }
        
        // Add memory handler for development
        if (getenv('APP_ENV') === 'development') {
            $memoryHandler = new MemoryHandler(LogLevel::DEBUG);
            $logger->addHandler($memoryHandler);
        }
        
        // Add a processor to include backtrace for errors
        $logger->addProcessor(function(LogMessage $message) {
            if (in_array($message->getLevel(), [LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::EMERGENCY])) {
                $extra = $message->getExtra();
                $extra['backtrace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                return new LogMessage(
                    $message->getMessage(),
                    $message->getContext(),
                    $message->getLevel(),
                    $message->getChannel(),
                    $extra
                );
            }
            return $message;
        });
        
        return $logger;
    }
}