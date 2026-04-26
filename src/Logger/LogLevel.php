<?php declare(strict_types=1);

namespace MetaCat\Logger;

/**
 * Log levels defined by RFC 5424
 */
final class LogLevel
{
    public const EMERGENCY = 'emergency';
    public const ALERT     = 'alert';
    public const CRITICAL  = 'critical';
    public const ERROR     = 'error';
    public const WARNING   = 'warning';
    public const NOTICE    = 'notice';
    public const INFO      = 'info';
    public const DEBUG     = 'debug';
    
    protected const LEVELS = [
        self::DEBUG     => 100,
        self::INFO      => 200,
        self::NOTICE    => 250,
        self::WARNING   => 300,
        self::ERROR     => 400,
        self::CRITICAL  => 500,
        self::ALERT     => 550,
        self::EMERGENCY => 600,
    ];
    
    public static function getLevelCode(string $level): int
    {
        return self::LEVELS[strtolower($level)] ?? 0;
    }
    
    public static function isValid(string $level): bool
    {
        return isset(self::LEVELS[strtolower($level)]);
    }
    
    public static function getAll(): array
    {
        return array_keys(self::LEVELS);
    }
    
    public static function isHigherOrEqual(string $level1, string $level2): bool
    {
        return self::getLevelCode($level1) >= self::getLevelCode($level2);
    }
    
    public static function isLower(string $level1, string $level2): bool
    {
        return self::getLevelCode($level1) < self::getLevelCode($level2);
    }
    
    public static function getLevelName(int $code): ?string
    {
        foreach (self::LEVELS as $name => $levelCode) {
            if ($levelCode === $code) {
                return $name;
            }
        }
        
        return null;
    }
    
    public static function getDefault(): string
    {
        return self::INFO;
    }
}