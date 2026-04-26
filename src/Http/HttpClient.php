<?php declare(strict_types = 1);

namespace MetaCat\Http;

use Throwable;
use RuntimeException;
use Swoole\Coroutine\Http\Client;

/**
 * Async HTTP client.
 */
class HttpClient
{
    protected array  $headers = [];
    protected string $body    = '';
    protected int    $timeout = 30;
    protected bool   $followRedirects = true;
    protected int    $maxRedirects   = 5;
    protected bool   $useDefer       = true;
    protected int    $retryAttempts  = 3;
    protected float  $retryDelaySeconds = 1.0;
    protected array  $options = [];
    /** @var Client|null */
    protected ?Client $client = null;

    /**
     * Create a new request.
     *
     * @param string $url
     * @param string $method
     */
    public function __construct(protected string $url, protected string $method = 'GET')
    {}

    /**
     * Add or overwrite a request header.
     *
     * @param string $name
     * @param string $value
     * @return self
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Set the request body.
     *
     * @param string $body
     * @return self
     */
    public function withBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Define a timeout for the whole request.
     *
     * @param int $timeout
     * @return self
     */
    public function withTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Enable or disable following redirects.
     *
     * @param bool $follow
     * @return self
     */
    public function withFollowRedirects(bool $follow): self
    {
        $this->followRedirects = $follow;
        return $this;
    }

    /**
     * Set the maximum number of redirects to follow.
     *
     * @param int $max
     * @return self
     */
    public function withMaxRedirects(int $max): self
    {
        $this->maxRedirects = max(1, $max);
        return $this;
    }

    /**
     * Enable or disable Swoole’s `defer` option.
     *
     * @param bool $defer
     * @return self
     */
    public function useDefer(bool $defer = true): self
    {
        $this->useDefer = $defer;
        return $this;
    }

    /**
     * Configure retry behaviour.
     *
     * @param int $attempts
     * @param float $initialDelaySeconds
     * @return self
     */
    public function withRetry(int $attempts, float $initialDelaySeconds = 1.0): self
    {
        $this->retryAttempts     = max(1, $attempts);
        $this->retryDelaySeconds = $initialDelaySeconds;
        return $this;
    }

    /**
     * Add a custom Swoole client option.
     *
     * @param string $option
     * @param mixed $value
     * @return self
     */
    public function withOption(string $option, $value): self
    {
        $this->options[$option] = $value;
        return $this;
    }

    /**
     * Execute the request and return the HTTP response.
     *
     * @return HttpResponse
     * @throws RuntimeException
     */
    public function send(): HttpResponse
    {
        $currentUrl  = $this->url;
        $redirectCnt = 0;
        $seenUrls    = [];

        while ($redirectCnt < $this->maxRedirects) {
            $response = $this->executeWithRetry($currentUrl);

            if ($this->handleRedirect($response, $currentUrl, $seenUrls, $redirectCnt)) {
                continue;
            }

            return $response;
        }

        throw new RuntimeException(
            "Maximum redirects ({$this->maxRedirects}) exceeded for {$this->url}"
        );
    }

    /**
     * Execute the HTTP call with retry logic.
     *
     * @param string $url
     * @return HttpResponse
     * @throws RuntimeException
     */
    protected function executeWithRetry(string $url): HttpResponse
    {
        $lastError = null;

        for ($attempt = 0; $attempt < $this->retryAttempts; $attempt++) {
            try {
                $this->prepareClient($url);
                $this->configureClient();
                $path = $this->getPathFromUrl($url);

                if (!$this->client->execute($path)) {
                    throw new RuntimeException(
                        "Execute failed: {$this->client->errMsg} (code {$this->client->errCode})"
                    );
                }

                $this->client->recv();
                return $this->parseResponse($this->client);
            } catch (Throwable $e) {
                $lastError = $e;
                $sleep = $this->retryDelaySeconds * (2 ** $attempt);
                usleep((int)($sleep * 1_000_000));
            }
        }

        throw new RuntimeException(
            "Request to {$url} failed after {$this->retryAttempts} attempts: {$lastError->getMessage()}"
        );
    }

    /**
     * Handle HTTP redirects when needed.
     *
     * @param HttpResponse $response
     * @param string $currentUrl
     * @param array $seenUrls
     * @param int $redirectCnt
     * @return bool true if a redirect was processed
     */
    protected function handleRedirect(
        HttpResponse $response,
        string $currentUrl,
        array &$seenUrls,
        int &$redirectCnt
    ): bool {
        if ($this->followRedirects &&
            $response->getStatusCode() >= 300 &&
            $response->getStatusCode() < 400
        ) {
            $location = $response->getHeader('Location');
            if (!$location) {
                return false;
            }

            $newUrl = $this->resolveRedirectUrl($currentUrl, $location);

            if (in_array($newUrl, $seenUrls, true)) {
                return false;
            }

            $seenUrls[] = $newUrl;
            $this->client->close();
            $this->client = null;
            $redirectCnt++;

            $this->url = $newUrl; // update for next loop
            return true;
        }

        return false;
    }

    /**
     * Create or update the underlying Swoole client.
     *
     * @param string $url
     * @return void
     */
    protected function prepareClient(string $url): void
    {
        if ($this->client === null) {
            $this->client = $this->createClient($url);
        } else {
            $scheme = parse_url($url, PHP_URL_SCHEME) ?? 'http';
            $this->client->host = parse_url($url, PHP_URL_HOST);
            $this->client->port = (int)(parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80));
            $this->client->ssl  = $scheme === 'https';
        }
    }

    /**
     * Instantiate a new Swoole client for the given URL.
     *
     * @param string $url
     * @return Client
     */
    protected function createClient(string $url): Client
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?? 'http';
        $host   = parse_url($url, PHP_URL_HOST);
        $port   = (int)(parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80));
        $ssl    = $scheme === 'https';
        return new Client($host, $port, $ssl);
    }

    /**
     * Apply configuration options to the Swoole client.
     *
     * @return void
     */
    protected function configureClient(): void
    {
        $default = [
            'timeout'              => $this->timeout,
            'ssl_verify_peer'      => true,
            'ssl_allow_self_signed'=> false,
            'keep_alive'           => true,
            'defer'                => $this->useDefer,
        ];
        $this->client->set(array_merge($default, $this->options));
        $this->client->setMethod($this->method);
        $this->client->setHeaders($this->headers);
        $this->client->setData($this->body);
    }

    /**
     * Extract the request path (including query) from a URL.
     *
     * @param string $url
     * @return string
     */
    protected function getPathFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $path  = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';
        return $query ? "{$path}?{$query}" : $path;
    }

    /**
     * Convert the Swoole client state to a HttpResponse object.
     *
     * @param Client $client
     * @return HttpResponse
     */
    protected function parseResponse(Client $client): HttpResponse
    {
        return new HttpResponse(
            $client->statusCode,
            $client->body,
            $this->parseHeaders($client->headers)
        );
    }

    /**
     * Normalise Swoole headers into an associative array.
     *
     * @param array $headers
     * @return array
     */
    protected function parseHeaders(array $headers): array
    {
        $parsed = [];
        foreach ($headers as $k => $v) {
            $parsed[$k] = is_array($v) ? implode(', ', $v) : $v;
        }
        return $parsed;
    }

    /**
     * Resolve a relative redirect location to an absolute URL.
     *
     * @param string $currentUrl
     * @param string $location
     * @return string
     */
    protected function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $base = parse_url($currentUrl);
        $scheme = $base['scheme'] ?? 'http';
        $host   = $base['host'] ?? '';
        $port   = $base['port'] ?? ($scheme === 'https' ? 443 : 80);
        $basePath = rtrim(dirname($base['path'] ?? '/'), '/');
        $newPath = $basePath . '/' . ltrim($location, '/');
        return "{$scheme}://{$host}" . ($port ? ":{$port}" : '') . $newPath;
    }
}