<?php declare(strict_types = 1);
namespace MetaCat;

use MetaCat\Validator;

class Config
{
    public const DEFAULT_USER_AGENT = 'MetaCat/1.0';
    public const DEFAULT_BODY_SIZE_LIMIT = 50 * 1024 * 1024;
    public const DEFAULT_TIMEOUT = 10;
    public const DEFAULT_RETRIES = 3;
    public const DEFAULT_VERIFY_PEER = true;
    public const DEFAULT_ALLOWED_MIME_TYPES = [
        'text/xml',
        'application/xml',
        'application/gzip',
        'application/x-gzip'
    ];
    public const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    /**
     * Config constructor.
     *
     * @param string $baseUrl
     * @param bool $respectRobots
     * @param string $userAgent
     * @param int $timeout
     * @param int $retries
     * @param int $crawelDelay
     * @param int $bodySizeLimit
     * @param bool $verifyPeer
     * @param array $allowedImageExtensions
     * @param array $allowedMimeTypes
     * @throws InvalidConfigException
     */
    public function __construct(
        protected string $baseUrl,
        protected bool $respectRobots = true,
        protected string $userAgent = self::DEFAULT_USER_AGENT,
        protected int $timeout = self::DEFAULT_TIMEOUT,
        protected int $retries = self::DEFAULT_RETRIES,
        protected int $crawelDelay = 0,
        protected int $bodySizeLimit = self::DEFAULT_BODY_SIZE_LIMIT,
        protected bool $verifyPeer = self::DEFAULT_VERIFY_PEER,
        protected array $allowedImageExtensions = self::ALLOWED_IMAGE_EXTENSIONS,
        protected array $allowedMimeTypes = self::DEFAULT_ALLOWED_MIME_TYPES
    ) {
        if (!Validator::validateUrl($baseUrl)) {
            throw new \InvalidArgumentException('Invalid base URL');
        }
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getBodySizeLimit(): int
    {
        return $this->bodySizeLimit;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getRetries(): int
    {
        return $this->retries;
    }

    public function getCrawelDelay(): int
    {
        return $this->crawelDelay;
    }

    public function verifyPeer(): bool
    {
        return $this->verifyPeer;
    }

    public function respectRobots(): bool
    {
        return $this->respectRobots;
    }

    public function getBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function getBaseHost(): string
    {
        return ltrim(parse_url($this->getBaseUrl(), PHP_URL_HOST), 'www.');
    }

    public function getAllowedImageExtensions(): array
    {
        return $this->allowedImageExtensions;
    }

    public function getAllowedMimeTypes(): array
    {
        return $this->allowedMimeTypes;
    }
}