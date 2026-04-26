<?php

declare(strict_types=1);

namespace MetaCat\Logger;

/**
 * Log message structure
 */
final class LogMessage
{
    protected string $message;
    protected array $context;
    protected string $level;
    protected string $channel;
    protected float $timestamp;
    protected array $extra;
    
    public function __construct(
        string $message,
        array $context = [],
        string $level = LogLevel::INFO,
        string $channel = 'app',
        array $extra = []
    ) {
        $this->message = $message;
        $this->context = $context;
        $this->level = $level;
        $this->channel = $channel;
        $this->timestamp = microtime(true);
        $this->extra = $extra;
    }
    
    public function getMessage(): string
    {
        return $this->message;
    }
    
    public function getContext(): array
    {
        return $this->context;
    }
    
    public function getLevel(): string
    {
        return $this->level;
    }
    
    public function getChannel(): string
    {
        return $this->channel;
    }
    
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
    
    public function getExtra(): array
    {
        return $this->extra;
    }
    
    public function getDateTime(): \DateTimeImmutable
    {
        $seconds = (int) $this->timestamp;
        $microseconds = (int) (($this->timestamp - $seconds) * 1000000);
        
        return \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, $microseconds)
        );
    }
    
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'context' => $this->context,
            'level' => $this->level,
            'channel' => $this->channel,
            'timestamp' => $this->timestamp,
            'datetime' => $this->getDateTime()->format('c'),
            'extra' => $this->extra,
        ];
    }
    
    public function format(): string
    {
        $datetime = $this->getDateTime()->format('Y-m-d H:i:s.u');
        $level = strtoupper($this->level);
        $channel = strtoupper($this->channel);
        
        return sprintf(
            '[%s] %s.%s: %s %s',
            $datetime,
            $channel,
            $level,
            $this->interpolateMessage(),
            json_encode($this->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
    
    public function interpolateMessage(): string
    {
        $replace = [];
        foreach ($this->context as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $replace['{' . $key . '}'] = $value;
            }
        }
        
        return strtr($this->message, $replace);
    }
}