<?php declare(strict_types=1);
namespace MetaCat\Parser;

use MetaCat\Config;
use Swoole\Coroutine\Http\Client;
use MetaCat\Exceptions\InvalidRobotsException;

/**
 * Parses robots.txt content and provides rules for crawling.
 */
class RobotsTxtParser
{
    /** @var array<string, array{allow: array<string>, disallow: array<string>}> Rules per user agent */
    protected array $rules = [];

    /** @var array<string> Sitemap URLs declared in robots.txt */
    protected array $sitemaps = [];

    /** @var array<string, int> Crawl delay per user agent */
    protected array $crawlDelay = [];

    /**
     * RobotsTxtParser constructor.
     *
     * @param Config $config Configuration object
     */
    public function __construct(protected Config $config)
    {
        $this->rules['*'] = [
            'allow' => [],
            'disallow' => []
        ];
    }

    /**
     * Fetches and parses robots.txt content.
     *
     * @return void
     * @throws InvalidRobotsException If robots.txt is unreachable or invalid
     */
    public function parse(): void
    {
        $parsed = parse_url($this->config->getBaseUrl());
        $schema = $parsed['schema'] ?? 'http';
        $ssl = $schema === 'https';
        $port = $parsed['port'] ?? ($ssl ? 443 : 80);
        $path = '/robots.txt';
        $host = 'www.' . $this->config->getBaseHost();

        $client = new Client($host, $port, $ssl);

        $client->set(['timeout' => $this->config->getTimeout()]);

        $client->setHeaders([
            'User-Agent' => $this->config->getUserAgent(),
        ]);

        $success = $client->get($path);
        if (!$success) {
            throw new InvalidRobotsException("Failed to fetch robots.txt: {$client->errCode} - {$client->errMsg}");
        }

        $status = $client->statusCode;
        if ($status >= 400) {
            throw new InvalidRobotsException("robots.txt not found or unreachable (HTTP {$status})");
        }

        $content = $client->body;
        if ($content === false) {
            throw new InvalidRobotsException("Empty or invalid robots.txt content");
        }

        $client->close();
        $this->process($content);
    }

    /**
     * Processes robots.txt content into structured rules.
     *
     * @param string $content Robots.txt content
     * @return void
     */
    protected function process(string $content): void
    {
        $lines = explode("\n", $content);
        $currentAgents = ['*'];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $key = strtolower(trim($key));
            $value = trim($value);

            if ($key === 'user-agent') {
                $currentAgents = array_map('strtolower', array_map('trim', explode(',', $value)));
                continue;
            }

            if ($key === 'disallow' || $key === 'allow') {
                foreach ($currentAgents as $agent) {
                    $this->rules[$agent][$key][] = $value;
                }
                continue;
            }

            if ($key === 'crawl-delay') {
                foreach ($currentAgents as $agent) {
                    $this->crawlDelay[$agent] = (int)$value;
                }
                continue;
            }

            if ($key === 'sitemap') {
                $this->sitemaps[] = $value;
            }
        }
    }

    /**
     * Checks if a URL is allowed by robots rules.
     *
     * @param string $url URL to check
     * @return bool True if allowed, false otherwise
     */
    public function isAllowed(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $agentRules = $this->getAgentRules();
        $allowed = true;

        foreach ($agentRules['disallow'] as $rule) {
            if ($this->matchRule($path, $rule)) {
                $allowed = false;
                break;
            }
        }

        if ($allowed) {
            foreach ($agentRules['allow'] as $rule) {
                if ($this->matchRule($path, $rule)) {
                    $allowed = true;
                    break;
                }
            }
        }

        return $allowed;
    }

    /**
     * Matches a path against a robots rule.
     *
     * @param string $path Path to match
     * @param string $rule Rule pattern (supports * as wildcard)
     * @return bool True if matches
     */
    protected function matchRule(string $path, string $rule): bool
    {
        if ($rule === '') {
            return false;
        }

        $regex = preg_quote($rule, '/');
        $regex = str_replace('\*', '.*', $regex);
        $regex = str_replace('\$', '$', $regex);

        return (bool)preg_match("/^{$regex}/", $path);
    }

    /**
     * Returns rules for the current user agent.
     *
     * @return array{allow: array<string>, disallow: array<string>}
     */
    public function getAgentRules(): array
    {
        $ua = strtolower($this->config->getUserAgent());
        return $this->rules[$ua] ?? $this->rules['*'];
    }

    /**
     * Returns sitemap URLs declared in robots.txt.
     *
     * @return array<string>
     */
    public function getSitemaps(): array
    {
        return array_unique($this->sitemaps);
    }

    /**
     * Returns crawl delay for current user agent.
     *
     * @return int|null Crawl delay in seconds, or null if not set
     */
    public function getCrawlDelay(): ?int
    {
        $ua = strtolower($this->config->getUserAgent());
        return $this->crawlDelay[$ua] ?? $this->crawlDelay['*'] ?? null;
    }
}
