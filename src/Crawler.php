<?php declare(strict_types = 1);

namespace MetaCat;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use MetaCat\Config;
use MetaCat\Exceptions\{
    HttpRequestException,
    CrawlingException
};
use MetaCat\Interfaces\Queue\UrlQueueInterface;
use MetaCat\Logger\Logger;
use MetaCat\Parser\HtmlParser;
use MetaCat\Queue\PriorityUrlQueue;
use MetaCat\Parser\RobotsTxtParser;
use MetaCat\Parser\SitemapParser;
use function Amp\delay;

/**
 * Main site crawler class for discovering and processing website content
 */
class Crawler
{
    public const DEFAULT_CRAWL_DELAY = 0; // seconds
    public const MAX_PAGES_PER_SITE = 2;
    public const MAX_CRAWL_DEPTH = 1;

    protected Config $config;
    protected SitemapParser $sitemapParser;
    protected RobotsTxtParser $robotsParser;
    protected Logger $logger;
    protected UrlQueueInterface $queue;

    protected array $crawledUrls = [];
    protected array $discoveredUrls = [];
    protected int $totalCrawled = 0;
    protected bool $respectRobotsTxt = true;
    protected array $crawlRules = [];
    protected float $crawlDelay;
    protected string $userAgent;

    /**
     * SiteCrawler constructor.
     *
     * @param Config $config Main configuration
     * @param Logger $logger PSR-3 logger
     */
    public function __construct(
        Config $config,
        Logger $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->queue = new PriorityUrlQueue();

        // Initialize parsers
        $this->robotsParser = new RobotsTxtParser($config);
        $this->robotsParser->parse();
        $this->sitemapParser = new SitemapParser($config, $this->robotsParser);

        $this->userAgent = $config->getUserAgent();
        $this->crawlDelay = $this->robotsParser->getCrawlDelay() ?? 0;
        $this->respectRobotsTxt = $config->respectRobots();

        $this->logger->info('SiteCrawler initialized for {url}', [
            'url' => $config->getBaseUrl()
        ]);
    }

    /**
     * Crawl the entire website
     *
     * @return array Statistics about the crawling process
     * @throws CrawlingException
     */
    public function crawlSite(): array
    {
        $startTime = microtime(true);
        
        try {
            $this->logger->info('Starting website crawling for {url}', [
                'url' => $this->config->getBaseUrl()
            ]);
            
            // Parse robots.txt if enabled
            if ($this->respectRobotsTxt) {
                $this->parseRobotsTxt();
            }
            
            // Try to discover and crawl sitemap first
            $sitemapUrls = $this->discoverSitemapUrls();
            
            if (!empty($sitemapUrls)) {
                $this->logger->info('Found {count} sitemap URLs', [
                    'count' => count($sitemapUrls)
                ]);
                $this->crawlFromSitemap($sitemapUrls);
            } else {
                // Start crawling from base URL
                $this->logger->info('No sitemap found, starting crawl from base URL');
                $this->queue->enqueue($this->config->getBaseUrl(), 1);
                $this->crawl();
            }
            
            $endTime = microtime(true);
            
            $stats = [
                'total_crawled' => $this->totalCrawled,
                'total_discovered' => count($this->discoveredUrls),
                'execution_time' => round($endTime - $startTime, 2),
                'average_time_per_page' => $this->totalCrawled > 0 
                    ? round(($endTime - $startTime) / $this->totalCrawled, 3)
                    : 0,
            ];
            
            $this->logger->info('Crawling completed', $stats);
            
            return $stats;
            
        } catch (\Exception $e) {
            $this->logger->error('Crawling failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e
            ]);
            
            throw new CrawlingException(
                'Failed to crawl site: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Parse robots.txt and extract crawl rules
     */
    protected function parseRobotsTxt(): void
    {
        try {
            // Get crawl rules
            $this->crawlRules = [
                'disallowed' => $this->robotsParser->getAgentRules()['disallow'],
                'allowed' => $this->robotsParser->getAgentRules()['allow'],
                'sitemaps' => $this->robotsParser->getSitemaps()
            ];
            
            $this->logger->debug('Parsed robots.txt', [
                'crawl_delay' => $this->crawlDelay,
                'disallowed_count' => count($this->crawlRules['disallowed']),
                'sitemap_count' => count($this->crawlRules['sitemaps'])
            ]);
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to parse robots.txt: {message}', [
                'message' => $e->getMessage()
            ]);
            // Continue without robots.txt rules
        }
    }

    /**
     * Discover sitemap URLs from robots.txt or common locations
     *
     * @return array List of sitemap URLs
     */
    protected function discoverSitemapUrls(): array
    {
        $sitemapUrls = [];
        
        // Check robots.txt for sitemaps
        if (!empty($this->crawlRules['sitemaps'])) {
            $sitemapUrls = array_merge($sitemapUrls, $this->crawlRules['sitemaps']);
        }
        
        // Check common sitemap locations
        $commonSitemaps = [
            '/sitemap.xml',
            '/sitemap_index.xml',
            '/sitemap/sitemap.xml',
            '/sitemap.xml.gz',
            '/sitemap/sitemap.xml.gz'
        ];
        
        $baseUrl = $this->config->getBaseUrl();
        
        foreach ($commonSitemaps as $sitemapPath) {
            $sitemapUrl = $baseUrl . $sitemapPath;
            if ($this->checkUrlExists($sitemapUrl)) {
                $sitemapUrls[] = $sitemapUrl;
            }
        }
        
        return array_unique($sitemapUrls);
    }

    /**
     * Check if a URL exists and is accessible
     */
    protected function checkUrlExists(string $url): bool
    {
        try {
            $request = new Request($url, 'HEAD');
            $request->setHeader('User-Agent', $this->userAgent);
            $request->setTcpConnectTimeout($this->config->getTimeout());
            $request->setTransferTimeout($this->config->getTimeout());
            $request->setInactivityTimeout($this->config->getTimeout());
            $request->setTlsHandshakeTimeout($this->config->getTimeout());
            
            $response = HttpClientBuilder::buildDefault()->request($request);
            return $response->getStatus() === 200;            
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Crawl URLs from sitemap
     */
    protected function crawlFromSitemap(array $sitemapUrls): void
    {
        foreach ($sitemapUrls as $sitemapUrl) {
            try {
                $this->logger->debug('Crawling sitemap: {url}', ['url' => $sitemapUrl]);
                
                $sitemapData = $this->sitemapParser->parse($sitemapUrl);
                
                if (isset($sitemapData['urls'])) {
                    foreach ($sitemapData['urls'] as $urlEntry) {
                        $url = $urlEntry['loc'];
                        
                        if ($this->shouldCrawlUrl($url)) {
                            $this->queue->enqueue($url, $this->calculatePriority($urlEntry));
                        }
                    }
                }
                
                // Process nested sitemaps
                if (isset($sitemapData['sitemaps'])) {
                    $this->crawlFromSitemap($sitemapData['sitemaps']);
                }
                
            } catch (\Exception $e) {
                $this->logger->warning('Failed to crawl sitemap {url}: {message}', [
                    'url' => $sitemapUrl,
                    'message' => $e->getMessage()
                ]);
            }
        }
        
        // Start crawling with URLs from sitemap
        $this->crawl();
    }

    /**
     * Main crawl loop
     */
    protected function crawl(): void
    {
        while (!$this->queue->isEmpty() && 
               $this->totalCrawled < self::MAX_PAGES_PER_SITE) {
            
            $url = $this->queue->dequeue();
            
            // Skip if already crawled
            if (in_array($url, $this->crawledUrls)) {
                continue;
            }
            
            // Check robots.txt rules
            if ($this->respectRobotsTxt && !$this->isUrlAllowed($url)) {
                $this->logger->debug('Skipping URL blocked by robots.txt: {url}', [
                    'url' => $url
                ]);
                continue;
            }
            
            try {
                $this->processPage($url);
                $this->crawledUrls[] = $url;
                
                // Respect crawl delay
                if ($this->crawlDelay > 0) {
                    delay($this->crawlDelay);
                }
                
            } catch (\Exception $e) {
                $this->logger->warning('Failed to crawl page {url}: {message}', [
                    'url' => $url,
                    'message' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Process a single page (no storage)
     */
    protected function processPage(string $url): void
    {
        $this->logger->debug('Crawling page: {url}', ['url' => $url]);
        
        try {
            // Fetch page content
            $html = $this->fetchPage($url);
            
            // Parse HTML content
            $htmlParser = new HtmlParser($html, $this->config);
            $pageData = $htmlParser->parse();
            
            // Add metadata
            $pageData['url'] = $url;
            $pageData['crawled_at'] = date('c');
            $pageData['domain'] = $this->config->getBaseHost();
            $pageData['crawl_depth'] = $this->calculateDepth($url);
            $pageData['page_size'] = strlen($html);
            
            // Log page data (instead of storing)
            $this->logger->info('Crawled page: {url} ({title})', [
                'url' => $url,
                'title' => substr($pageData['title'], 0, 100)
            ]);
            
            // Process discovered links
            $this->processDiscoveredLinks($pageData['links'], $url);
            
            $this->totalCrawled++;
            
        } catch (\Exception $e) {
            throw new CrawlingException(
                "Failed to crawl page {$url}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Fetch page content with retries
     */
    protected function fetchPage(string $url): string
    {
        $maxRetries = $this->config->getRetries();
        $retryDelay = 1.0;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $request = new Request($url);
                $request->setHeader('User-Agent', $this->userAgent);
                $request->setTcpConnectTimeout($this->config->getTimeout());
                $request->setTransferTimeout($this->config->getTimeout());
                $request->setInactivityTimeout($this->config->getTimeout());
                $request->setTlsHandshakeTimeout($this->config->getTimeout());
                
                $response = HttpClientBuilder::buildDefault()->request($request);
                if ($response->getStatus() !== 200) {
                    throw new HttpRequestException(
                        "HTTP {$response->getStatus()} for {$url}"
                    );
                }
                
                $contentType = $response->getHeader('content-type');
                // Only process HTML content
                if (!str_contains($contentType, 'text/html') && 
                    !str_contains($contentType, 'application/xhtml+xml')) {
                    throw new HttpRequestException(
                        "Non-HTML content type: {$contentType}"
                    );
                }
                
                return $response->getBody()->buffer();
                
            } catch (\Exception $e) {
                if ($attempt === $maxRetries) {
                    throw $e;
                }
                
                $this->logger->warning(
                    'Fetch attempt {attempt} failed for {url}: {message}',
                    [
                        'attempt' => $attempt,
                        'url' => $url,
                        'message' => $e->getMessage()
                    ]
                );
                
                delay($retryDelay);
                $retryDelay *= 2; // Exponential backoff
            }
        }
        
        throw new HttpRequestException("Failed to fetch {$url} after {$maxRetries} attempts");
    }

    /**
     * Process discovered links from a page
     */
    protected function processDiscoveredLinks(array $links, string $sourceUrl): void
    {
        $depth = $this->calculateDepth($sourceUrl);
        
        if ($depth >= self::MAX_CRAWL_DEPTH) {
            return;
        }
        
        foreach ($links['internal'] as $link) {
            $url = $link['url'];
            
            // Skip if already discovered or crawled
            if (in_array($url, $this->discoveredUrls) || 
                in_array($url, $this->crawledUrls)) {
                continue;
            }
            
            // Check if URL should be crawled
            if ($this->shouldCrawlUrl($url)) {
                $this->discoveredUrls[] = $url;
                
                // Calculate priority based on link context
                $priority = $this->calculateLinkPriority($link, $depth);
                $this->queue->enqueue($url, $priority);
            }
        }
    }

    /**
     * Determine if a URL should be crawled
     */
    protected function shouldCrawlUrl(string $url): bool
    {
        // Check if URL belongs to the same domain
        $urlHost = parse_url($url, PHP_URL_HOST);
        if ($urlHost !== $this->config->getBaseHost()) {
            return false;
        }
        
        // Skip common non-content URLs
        $skipPatterns = [
            '/\.(jpg|jpeg|png|gif|webp|svg|css|js|pdf|zip|rar|tar|gz)$/i',
            '/\/feed\/?$/',
            '/\/wp-json\//',
            '/\/api\//',
            '/\/admin\//',
            '/\/login\//',
            '/\/logout\//',
            '/\/register\//',
            '/\/search\//',
        ];
        
        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return false;
            }
        }
        
        // Check against robots.txt disallowed paths
        if ($this->respectRobotsTxt && !$this->isUrlAllowed($url)) {
            return false;
        }
        
        return true;
    }

    /**
     * Check if URL is allowed by robots.txt
     */
    protected function isUrlAllowed(string $url): bool
    {
        if (!$this->respectRobotsTxt || empty($this->crawlRules['disallowed'])) {
            return true;
        }
        
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        
        // Check disallow rules first
        foreach ($this->crawlRules['disallowed'] as $disallowedPath) {
            if (str_contains($path, $disallowedPath)) {
                // Check if there's an explicit allow rule
                foreach ($this->crawlRules['allowed'] as $allowedPath) {
                    if (str_contains($path, $allowedPath)) {
                        return true;
                    }
                }
                return false;
            }
        }
        
        return true;
    }

    /**
     * Calculate crawl depth from URL
     */
    protected function calculateDepth(string $url): int
    {
        $baseUrl = $this->config->getBaseUrl();
        $basePath = parse_url($baseUrl, PHP_URL_PATH) ?: '/';
        $urlPath = parse_url($url, PHP_URL_PATH) ?: '/';
        
        // Remove base path from URL path
        if (str_starts_with($urlPath, $basePath)) {
            $urlPath = substr($urlPath, strlen($basePath));
        }
        
        // Count path segments
        $segments = array_filter(explode('/', $urlPath));
        return count($segments);
    }

    /**
     * Calculate priority for URL from sitemap data
     */
    protected function calculatePriority(array $urlEntry): int
    {
        $priority = 5; // Default priority
        
        if (!empty($urlEntry['priority'])) {
            $priority = (int)((float)$urlEntry['priority'] * 10);
        }
        
        // Boost home page
        $homeUrl = $this->config->getBaseUrl();
        if ($urlEntry['loc'] === $homeUrl || $urlEntry['loc'] === $homeUrl . '/') {
            $priority = 10;
        }
        
        return min(max($priority, 1), 10);
    }

    /**
     * Calculate priority for discovered links
     */
    protected function calculateLinkPriority(array $link, int $sourceDepth): int
    {
        $priority = 5;
        
        // Higher priority for links with relevant anchor text
        $anchorText = strtolower($link['text']);
        $relevantTerms = ['home', 'main', 'index', 'about', 'contact', 'product', 'service'];
        
        foreach ($relevantTerms as $term) {
            if (str_contains($anchorText, $term)) {
                $priority += 2;
                break;
            }
        }
        
        // Lower priority for nofollow links
        if (isset($link['nofollow']) && $link['nofollow']) {
            $priority -= 1;
        }
        
        // Lower priority for deeper links
        $priority -= min($sourceDepth, 3);
        
        return min(max($priority, 1), 10);
    }

    /**
     * Reset crawler state
     */
    public function reset(): void
    {
        $this->crawledUrls = [];
        $this->discoveredUrls = [];
        $this->totalCrawled = 0;
        $this->queue->clear();
        
        $this->logger->info('Crawler reset');
    }

    /**
     * Set crawl delay
     */
    public function setCrawlDelay(float $delay): self
    {
        $this->crawlDelay = $delay;
        return $this;
    }

    /**
     * Set user agent
     */
    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    /**
     * Set whether to respect robots.txt
     */
    public function setRespectRobotsTxt(bool $respect): self
    {
        $this->respectRobotsTxt = $respect;
        return $this;
    }
}