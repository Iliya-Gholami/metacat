<?php

declare(strict_types=1);

namespace MetaCat\Logger;

use MetaCat\Interfaces\Logger\LogHandlerInterface;

/**
 * File log handler - writes logs to a file
 */
class FileHandler implements LogHandlerInterface
{
    protected string $filePath;
    protected string $level;
    protected $fileHandle = null;
    protected int $maxFileSize;
    protected int $maxFiles;
    protected bool $useLocking;
    
    public function __construct(
        string $filePath,
        string $level = LogLevel::DEBUG,
        int $maxFileSize = 10485760, // 10MB
        int $maxFiles = 5,
        bool $useLocking = false
    ) {
        $this->filePath = $filePath;
        $this->level = LogLevel::isValid($level) ? $level : LogLevel::DEBUG;
        $this->maxFileSize = $maxFileSize;
        $this->maxFiles = $maxFiles;
        $this->useLocking = $useLocking;
        
        $this->ensureDirectoryExists();
    }
    
    protected function ensureDirectoryExists(): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
    
    public function handle(LogMessage $message): void
    {
        if (!$this->supports($message)) {
            return;
        }
        
        $this->rotateIfNeeded();
        
        $logLine = $message->format() . PHP_EOL;
        
        if ($this->useLocking) {
            $this->writeWithLocking($logLine);
        } else {
            $this->writeWithoutLocking($logLine);
        }
    }
    
    protected function writeWithLocking(string $logLine): void
    {
        if ($this->fileHandle === null) {
            $this->fileHandle = fopen($this->filePath, 'a');
        }
        
        if (flock($this->fileHandle, LOCK_EX)) {
            fwrite($this->fileHandle, $logLine);
            flock($this->fileHandle, LOCK_UN);
        }
    }
    
    protected function writeWithoutLocking(string $logLine): void
    {
        file_put_contents($this->filePath, $logLine, FILE_APPEND);
    }
    
    protected function rotateIfNeeded(): void
    {
        if (!file_exists($this->filePath)) {
            return;
        }
        
        if (filesize($this->filePath) >= $this->maxFileSize) {
            $this->rotate();
        }
    }
    
    protected function rotate(): void
    {
        // Close file handle if open
        if ($this->fileHandle !== null) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
        
        // Remove oldest backup
        $oldest = $this->filePath . '.' . $this->maxFiles;
        if (file_exists($oldest)) {
            unlink($oldest);
        }
        
        // Rotate existing backups
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $old = $this->filePath . '.' . $i;
            $new = $this->filePath . '.' . ($i + 1);
            if (file_exists($old)) {
                rename($old, $new);
            }
        }
        
        // Rotate current file
        rename($this->filePath, $this->filePath . '.1');
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
    
    public function __destruct()
    {
        if ($this->fileHandle !== null) {
            fclose($this->fileHandle);
        }
    }
}