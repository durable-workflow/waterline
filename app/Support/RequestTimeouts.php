<?php

namespace Waterline\Support;

use ReflectionMethod;

final class RequestTimeouts
{
    public static function browserMilliseconds(): int
    {
        return (int) ceil(self::seconds(config('waterline.browser.request_timeout_seconds', 65),
            'WATERLINE_BROWSER_REQUEST_TIMEOUT', min(intdiv(PHP_INT_MAX, 1000), 2147483.647)) * 1000);
    }

    /** @return array<string, float> */
    public static function serviceClientOptions(string $clientClass): array
    {
        $configured = config('waterline.service.request_timeout_seconds');
        if ($configured === null) {
            return []; // Inherit the installed SDK policy; preserve optional older SDKs.
        }
        $seconds = self::seconds($configured, 'WATERLINE_SERVER_REQUEST_TIMEOUT', intdiv(PHP_INT_MAX, 1000));
        // The named constructor option is the public SDK 3.4 capability. This
        // path runs only when constructing an optional service-mode client.
        foreach ((new ReflectionMethod($clientClass, '__construct'))->getParameters() as $parameter) {
            if ($parameter->getName() === 'requestTimeoutSeconds') {
                return ['requestTimeoutSeconds' => $seconds];
            }
        }
        throw new RequestTimeoutException(
            'WATERLINE_SERVER_REQUEST_TIMEOUT requires durable-workflow/sdk 3.4.0 or later; upgrade the optional SDK or leave the setting unset to inherit its policy.',
            'backend_capability_unavailable', 501,
        );
    }

    private static function seconds($value, string $setting, float $maximum): float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            throw new RequestTimeoutException($setting.' must be a finite number of seconds between 0.001 and '.$maximum.'.');
        }
        $seconds = (float) $value;
        if (!is_finite($seconds) || $seconds < 0.001 || $seconds > $maximum) {
            throw new RequestTimeoutException($setting.' must be a finite number of seconds between 0.001 and '.$maximum.'.');
        }
        return $seconds;
    }
}
