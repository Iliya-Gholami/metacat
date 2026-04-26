<?php declare(strict_types = 1);

namespace MetaCat\Parser;

use DOMNode;
use DOMXPath;
use DOMDocument;
use MetaCat\Config;
use Swoole\Coroutine\Http\Client;
use MetaCat\Exceptions\{HttpRequestException, SitemapParseException};

class SitemapParser
{
    /**
     * SitemapParser constructor.
     *
     * @param SitemapConfig $config
     * @param RobotsTxtParser $robots
     */
    public function __construct(protected Config $config, protected RobotsTxtParser $robots)
    {}

    /**
     * Fetches and decodes sitemap content.
     *
     * @param string $url
     * @return string
     * @throws HttpRequestException
     */
    protected function fetchAndDecode(string $url): string
    {
        $raw = $this->fetch($url);
        return $this->maybeDecodeGz($raw);
    }

    /**
     * Fetches sitemap content via HTTP.
     *
     * @param string $url
     * @return string
     * @throws HttpRequestException
     */
    protected function fetch(string $url): string
    {
        $parsed = parse_url($url);
        $schema = $parsed['schema'] ?? 'http';
        $ssl = $schema === 'https';
        $port = $parsed['port'] ?? ($ssl ? 443 : 80);
        $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        $host = isset($parsed['host']) ? ltrim('www.', $parsed['host']) : $this->config->getBaseHost();
        $host = 'www.' . $host;

        $client = new Client($host, $port, $ssl);

        $client->set(['timeout' => $this->config->getTimeout(), 'follow_location' => true]);
        $client->setHeaders(['User-Agent' => $this->config->getUserAgent()]);

        $success = $client->get($path);

        if (!$success) {
            throw new \Exception("Failed to fetch robots.txt: {$client->errCode} - {$client->errMsg}");
        }

        echo $client->statusCode, PHP_EOL;

        return $client->body;
    }

    /**
     * Decodes gzipped content if applicable.
     *
     * @param string $content
     * @return string
     */
    protected function maybeDecodeGz(string $content): string
    {
        if (str_starts_with($content, "\x1f\x8b\x08")) {
            return gzdecode($content) ?: $content;
        }
        return $content;
    }

    /**
     * Removes control characters from content.
     *
     * @param string $content
     * @return string
     */
    protected function removeControlCharacters(string $content): string
    {
        return str_replace(["\x03", "\x0b", "\x0"], "", $content);
    }

    /**
     * Checks if URL is allowed by robots.txt.
     *
     * @param string $url
     * @return void
     * @throws SitemapParseException
     */
    protected function assertRobotsAllowed(string $url): void
    {
        if (!$this->robots->isAllowed($url)) {
            throw new SitemapParseException("Blocked by robots.txt");
        }
    }

    /**
     * Parses sitemap content into structured data.
     *
     * @param string $url
     * @return array
     * @throws SitemapParseException
     * @throws HttpRequestException
     */
    public function parse(string $url): array
    {
        $this->assertRobotsAllowed($url);
        $content = $this->fetchAndDecode($url);
        $content = $this->removeControlCharacters($content);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($content)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new SitemapParseException(
                "Invalid sitemap XML: " . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return match ($this->detectSitemapType($dom)) {
            'sitemapindex' => $this->parseSitemapIndex($dom),
            'urlset' => $this->parseUrlSet($dom),
            'feed' => $this->parseFeed($dom),
            default => throw new SitemapParseException("Unknown sitemap format"),
        };
    }

    /**
     * Detects sitemap type from DOM.
     *
     * @param DOMDocument $dom
     * @return string
     */
    protected function detectSitemapType(DOMDocument $dom): string
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('x', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');
        $xpath->registerNamespace('video', 'http://www.google.com/schemas/sitemap-video/1.1');

        if ($xpath->query('//x:sitemapindex/x:sitemap/x:loc')->length > 0) {
            return 'sitemapindex';
        }

        if ($xpath->query('//x:url')->length > 0) {
            return 'urlset';
        }

        if ($xpath->query('//channel/item | //feed/entry')->length > 0) {
            return 'feed';
        }

        return 'unknown';
    }

    /**
     * Parses sitemap index format.
     *
     * @param DOMDocument $dom
     * @return array
     */
    protected function parseSitemapIndex(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('x', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $sitemaps = [];
        foreach ($xpath->query('//x:sitemapindex/x:sitemap/x:loc') as $node) {
            $sitemaps[] = trim($node->textContent);
        }

        return ['sitemaps' => $sitemaps];
    }

    /**
     * Parses standard URL set format.
     *
     * @param DOMDocument $dom
     * @return array
     */
    protected function parseUrlSet(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('x', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');
        $xpath->registerNamespace('video', 'http://www.google.com/schemas/sitemap-video/1.1');

        $urls = [];
        foreach ($xpath->query('//x:url') as $urlNode) {
            $entry = [
                'loc' => trim($xpath->evaluate('string(x:loc)', $urlNode)),
                'lastmod' => trim($xpath->evaluate('string(x:lastmod)', $urlNode)),
                'changefreq' => trim($xpath->evaluate('string(x:changefreq)', $urlNode)),
                'priority' => trim($xpath->evaluate('string(x:priority)', $urlNode)),
            ];

            $this->parseImages($xpath, $urlNode, $entry);
            $this->parseVideos($xpath, $urlNode, $entry);
            $this->parseAlternates($xpath, $urlNode, $entry);

            $urls[] = $entry;
        }

        return ['urls' => $urls];
    }

    /**
     * Parses image data from URL node.
     *
     * @param DOMXPath $xpath
     * @param DOMNode $urlNode
     * @param array $entry
     */
    protected function parseImages(DOMXPath $xpath, DOMNode $urlNode, array &$entry): void
    {
        $images = [];
        foreach ($xpath->query('image:image', $urlNode) as $imgNode) {
            $imgData = [
                'loc' => trim($xpath->evaluate('string(image:loc)', $imgNode)),
                'caption' => trim($xpath->evaluate('string(image:caption)', $imgNode)),
                'title' => trim($xpath->evaluate('string(image:title)', $imgNode)),
            ];
            if (array_filter($imgData)) {
                $images[] = $imgData;
            }
        }
        if ($images) {
            $entry['images'] = $images;
        }
    }

    /**
     * Parses video data from URL node.
     *
     * @param DOMXPath $xpath
     * @param DOMNode $urlNode
     * @param array $entry
     */
    protected function parseVideos(DOMXPath $xpath, DOMNode $urlNode, array &$entry): void
    {
        $videos = [];
        foreach ($xpath->query('video:video', $urlNode) as $videoNode) {
            $videoData = [
                'title' => trim($xpath->evaluate('string(video:title)', $videoNode)),
                'description' => trim($xpath->evaluate('string(video:description)', $videoNode)),
                'thumbnail' => trim($xpath->evaluate('string(video:thumbnail_loc)', $videoNode)),
                'content_loc' => trim($xpath->evaluate('string(video:content_loc)', $videoNode)),
            ];
            if (array_filter($videoData)) {
                $videos[] = $videoData;
            }
        }
        if ($videos) {
            $entry['videos'] = $videos;
        }
    }

    /**
     * Parses alternate language links.
     *
     * @param DOMXPath $xpath
     * @param DOMNode $urlNode
     * @param array $entry
     */
    protected function parseAlternates(DOMXPath $xpath, DOMNode $urlNode, array &$entry): void
    {
        $alternates = [];
        foreach ($xpath->query('x:link', $urlNode) as $linkNode) {
            if (strtolower($linkNode->getAttribute('rel')) === 'alternate') {
                $altData = [
                    'hreflang' => $linkNode->getAttribute('hreflang'),
                    'href' => $linkNode->getAttribute('href')
                ];
                if (array_filter($altData)) {
                    $alternates[] = $altData;
                }
            }
        }
        if ($alternates) {
            $entry['alternates'] = $alternates;
        }
    }

    /**
     * Parses RSS/Atom feed format.
     *
     * @param DOMDocument $dom
     * @return array
     */
    protected function parseFeed(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $items = $xpath->query('//channel/item | //feed/entry');
        $feeds = [];

        foreach ($items as $item) {
            $feeds[] = [
                'loc' => trim($xpath->evaluate('string(./link)', $item)),
                'title' => trim($xpath->evaluate('string(./title)', $item)),
                'pubDate' => trim($xpath->evaluate('string(./pubDate | ./updated)', $item)),
            ];
        }

        return ['feeds' => $feeds];
    }
}