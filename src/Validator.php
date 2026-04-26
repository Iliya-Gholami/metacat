<?php declare(strict_types = 1);

namespace MetaCat;

class Validator
{
    const VALID_URL_PATTERN = '/^https?:\/\/([a-zA-Z0-9-]+\.)+[a-zA-Z0-9-]+(\/\S*)*$/';

    const VALID_HTTP_METHODS = [
        'POST' => 0,
        'GET' => 0,
        'HEAD' => 0,
        'PUT' => 0,
        'DELETE' => 0
    ];

    /**
     * Checks if the url is valid or not
     * 
     * @param string $url
     * @return bool
     */
    public static function validateUrl(string $url): bool
    {
        return preg_match(static::VALID_URL_PATTERN, $url) !== false;
    }

    /**
     * Checks if the method is valid or not
     * 
     * @param string $method
     * @return bool
     */
    public static function validateMethod(string $method): bool
    {
        return isset(static::VALID_HTTP_METHODS[$method]);
    }
}