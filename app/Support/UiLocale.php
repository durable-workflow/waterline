<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Support\Facades\Lang;

final class UiLocale
{
    public static function resolve(mixed $locale = null): string
    {
        $locale ??= config('waterline.locale', 'en');

        if (! is_string($locale)) {
            return 'en';
        }

        $normalized = strtolower(str_replace('_', '-', $locale));
        if (in_array($normalized, ['uk', 'uk-ua'], true)) {
            return 'uk';
        }
        if (in_array($normalized, ['pt', 'pt-br'], true)) {
            return 'pt-BR';
        }

        return preg_match('/^es(?:-(?:[a-z]{2}|[0-9]{3}))?$/D', $normalized) === 1 ? 'es' : 'en';
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
