<?php declare(strict_types = 1);

namespace MetaCat\Queue;

use MetaCat\Interfaces\Queue\UrlQueueInterface;

/**
 * Priority-based URL queue implementation
 */
class PriorityUrlQueue implements UrlQueueInterface
{
    protected array $queues = [];
    protected array $urlMap = [];
    
    public function __construct()
    {
        // Initialize priority queues
        for ($i = 1; $i <= 10; $i++) {
            $this->queues[$i] = [];
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function enqueue(string $url, int $priority = 5): void
    {
        $priority = max(1, min(10, $priority));
        
        // Don't add duplicates
        if (isset($this->urlMap[$url])) {
            return;
        }
        
        $this->queues[$priority][] = $url;
        $this->urlMap[$url] = $priority;
    }
    
    /**
     * {@inheritdoc}
     */
    public function dequeue(): ?string
    {
        // Get highest priority non-empty queue
        for ($priority = 10; $priority >= 1; $priority--) {
            if (!empty($this->queues[$priority])) {
                $url = array_shift($this->queues[$priority]);
                unset($this->urlMap[$url]);
                return $url;
            }
        }
        
        return null;
    }
    
    /**
     * {@inheritdoc}
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }
    
    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        $total = 0;
        for ($i = 1; $i <= 10; $i++) {
            $total += count($this->queues[$i]);
        }
        return $total;
    }
    
    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->queues = [];
        $this->urlMap = [];
        
        for ($i = 1; $i <= 10; $i++) {
            $this->queues[$i] = [];
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function contains(string $url): bool
    {
        return isset($this->urlMap[$url]);
    }
}