<?php

declare(strict_types=1);

namespace MetaCat\Interfaces\Queue;

/**
 * URL queue interface for crawl management
 */
interface UrlQueueInterface
{
    /**
     * Add URL to queue with priority
     *
     * @param string $url URL to queue
     * @param int $priority Priority (1-10, 10 being highest)
     */
    public function enqueue(string $url, int $priority = 5): void;
    
    /**
     * Get next URL from queue
     *
     * @return string|null URL or null if queue is empty
     */
    public function dequeue(): ?string;
    
    /**
     * Check if queue is empty
     *
     * @return bool True if queue is empty
     */
    public function isEmpty(): bool;
    
    /**
     * Get queue size
     *
     * @return int Number of URLs in queue
     */
    public function count(): int;
    
    /**
     * Clear the queue
     */
    public function clear(): void;
    
    /**
     * Check if URL is in queue
     *
     * @param string $url URL to check
     * @return bool True if URL is in queue
     */
    public function contains(string $url): bool;
}