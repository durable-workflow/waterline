<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Support\Facades\Lang;

final class UiLocale
{
    public static function resolve(mixed $locale = null): string
    {
        $locale ??= config('waterline.locale', 'en');

        return is_string($locale) && in_array(strtolower(str_replace('_', '-', $locale)), ['uk', 'uk-ua'], true)
            ? 'uk'
            : 'en';
    }

    public static function text(string $message): string
    {
        $key = 'waterline::ui.'.$message;
        $translation = Lang::get($key, [], self::resolve(), false);

        if (! is_string($translation) || $translation === $key || $translation === '') {
            $translation = Lang::get($key, [], 'en', false);
        }

        return is_string($translation) && $translation !== $key && $translation !== '' ? $translation : $message;
    }
}
