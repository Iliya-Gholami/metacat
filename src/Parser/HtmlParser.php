<?php declare(strict_types = 1);

namespace MetaCat\Parser;

use DOMDocument;
use DOMXPath;
use MetaCat\Config;
use MetaCat\Exceptions\HtmlParserException;

/**
 * Parses HTML content into structured data for SEO and content analysis.
 *
 * @package MetaCat\Parser
 * @author MetaCat Team
 * @since 1.0.0
 */
final class HtmlParser
{
    /**
     * Valid meta tags for extraction.
     *
     * @var array<string>
     */
    public const VALID_META_TAGS = ['author', 'keywords', 'description', 'revised', 'viewport'];

    /**
     * Excluded link schemes (javascript:, mailto:, tel:).
     *
     * @var array<string>
     */
    public const EXCLUDED_LINK_SCHEMES = ['javascript:', 'mailto:', 'tel:'];

    /**
     * Excluded link relations (nofollow).
     *
     * @var array<string>
     */
    public const EXCLUDED_LINK_REL = ['nofollow'];

    /** @var DOMDocument DOM document for parsing */
    protected DOMDocument $dom;

    /** @var DOMXPath XPath processor for DOM queries */
    protected DOMXPath $xpath;

    /** @var string Base URL for resolving relative links */
    protected string $baseUrl;

    /** @var string Base host for determining internal links */
    protected string $baseHost;

    /**
     * HtmlParser constructor.
     *
     * @param string $html HTML content to parse
     * @param Config $config Configuration object
     * @throws HtmlParserException If HTML parsing fails
     */
    public function __construct(
        protected string $html,
        protected Config $config
    ) {
        $this->baseUrl = $config->getBaseUrl();
        $this->baseHost = $config->getBaseHost();
        
        $this->dom = new DOMDocument();
        $this->dom->encoding = 'utf-8';
        $this->dom->loadHTML('<?xml encoding="utf-8" ?>' . $this->html, LIBXML_NOWARNING | LIBXML_NOERROR);
        
        if ($this->dom->documentElement === null) {
            throw new HtmlParserException('Failed to parse HTML: Empty document');
        }
        
        $this->xpath = new DOMXPath($this->dom);
    }

    /**
     * Parses HTML content and returns structured data.
     *
     * @return array{
     *     title: string,
     *     meta: array<string, string>,
     *     headings: array<int, array{level: string, index: int, text: string}>,
     *     paragraphs: array<int, array{index: int, text: string}>,
     *     links: array{
     *         internal: array<int, array{index: int, url: string, text: string}>,
     *         external: array<int, array{index: int, url: string, text: string}>
     *     },
     *     images: array<int, array{index: int, src: string, alt: string}>
     * }
     */
    public function parse(): array
    {
        return [
            'title' => $this->getTitle(),
            'meta' => $this->getMeta(),
            'headings' => $this->getHeadings(),
            'paragraphs' => $this->getParagraphs(),
            'links' => $this->getLinks(),
            'images' => $this->getImages(),
        ];
    }

    /**
     * Extracts the title tag content.
     *
     * @return string Title content (empty string if not found)
     */
    protected function getTitle(): string
    {
        $titleNode = $this->dom->getElementsByTagName('title')->item(0);
        return $titleNode ? trim($titleNode->textContent) : '';
    }

    /**
     * Extracts all meta tags with valid names/properties.
     *
     * @return array<string, string> Meta data indexed by name/property
     */
    protected function getMeta(): array
    {
        $metaData = [];
        foreach ($this->dom->getElementsByTagName('meta') as $meta) {
            $name = mb_strtolower($meta->getAttribute('name'));
            $property = mb_strtolower($meta->getAttribute('property'));
            $content = $meta->getAttribute('content');
            
            if ($name && in_array($name, self::VALID_META_TAGS, true)) {
                $metaData[$name] = $content;
            }
            
            if ($property) {
                $metaData[$property] = $content;
            }
        }
        return $metaData;
    }

    /**
     * Extracts all headings (H1-H6) with their structure.
     *
     * @return array<int, array{level: string, index: int, text: string}>
     */
    protected function getHeadings(): array
    {
        $result = [];
        for ($level = 1; $level <= 6; $level++) {
            $nodes = $this->dom->getElementsByTagName('h' . $level);
            foreach ($nodes as $index => $node) {
                $result[] = [
                    'level' => 'h' . $level,
                    'index' => $index + 1,
                    'text' => trim($node->textContent),
                ];
            }
        }
        return $result;
    }

    /**
     * Extracts all paragraph elements with their content.
     *
     * @return array<int, array{index: int, text: string}>
     */
    protected function getParagraphs(): array
    {
        $result = [];
        foreach ($this->dom->getElementsByTagName('p') as $index => $p) {
            $result[] = [
                'index' => $index + 1,
                'text' => trim($p->textContent),
            ];
        }
        return $result;
    }

    /**
     * Extracts internal and external links with filtering.
     *
     * @return array{
     *     internal: array<int, array{index: int, url: string, text: string}>,
     *     external: array<int, array{index: int, url: string, text: string}>
     * }
     */
    protected function getLinks(): array
    {
        $internal = [];
        $external = [];

        foreach ($this->dom->getElementsByTagName('a') as $index => $link) {
            $href = $link->getAttribute('href');
            if (!$href || $this->isExcludedLink($href)) {
                continue;
            }

            $resolved = $this->resolveUrl($href);
            $host = parse_url($resolved, PHP_URL_HOST);

            if ($this->isNofollowLink($link)) {
                continue;
            }

            $linkData = [
                'index' => $index + 1,
                'url' => $resolved,
                'text' => trim($link->textContent),
            ];

            if ($host === $this->baseHost) {
                $internal[] = $linkData;
            } else {
                $external[] = $linkData;
            }
        }

        return [
            'internal' => array_values($internal),
            'external' => array_values($external),
        ];
    }

    /**
     * Extracts images with alt text and valid source.
     *
     * @return array<int, array{index: int, src: string, alt: string}>
     */
    protected function getImages(): array
    {
        $result = [];
        foreach ($this->dom->getElementsByTagName('img') as $index => $img) {
            $alt = trim($img->getAttribute('alt'));
            $src = trim($img->getAttribute('src'));
            
            if (!$alt || !$src) {
                continue;
            }
            
            $result[] = [
                'index' => $index + 1,
                'src' => $this->resolveUrl($src),
                'alt' => $alt,
            ];
        }
        return array_values($result);
    }

    /**
     * Resolves relative URLs to absolute URLs.
     *
     * @param string $url Relative or absolute URL
     * @return string Absolute URL
     */
    protected function resolveUrl(string $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME)) {
            return $url;
        }
        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Checks if a link should be excluded.
     *
     * @param string $href Link URL
     * @return bool True if link should be excluded
     */
    protected function isExcludedLink(string $href): bool
    {
        return str_starts_with($href, '#') 
            || in_array(strtolower($href), self::EXCLUDED_LINK_SCHEMES, true);
    }

    /**
     * Checks if a link has nofollow relation.
     *
     * @param \DOMElement $link Link element
     * @return bool True if link is nofollow
     */
    protected function isNofollowLink(\DOMElement $link): bool
    {
        $rel = strtolower($link->getAttribute('rel'));
        return str_contains($rel, 'nofollow');
    }
}