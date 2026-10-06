<?php

declare(strict_types=1);

namespace Waterline\Support;

final class RunApplicationContext
{
    private const FIELD_LIMIT = 20;
    private const LINK_LIMIT = 10;
    private const VALUE_LIMIT = 512;

    /** @param array<string, mixed> $detail */
    public static function annotate(array $detail): array
    {
        $type = $detail['workflow_type'] ?? $detail['class'] ?? null;
        $profiles = config('waterline.observability.workflow_types', []);
        $profile = is_string($type) && is_array($profiles) ? ($profiles[$type] ?? []) : [];
        $profile = is_array($profile) ? $profile : [];
        $detail['workflow_classification'] = WorkflowClassification::forType($type);

        $fields = [];
        $values = [];
        foreach (is_array($profile['fields'] ?? null) ? $profile['fields'] : [] as $name => $field) {
            if (count($fields) >= self::FIELD_LIMIT) {
                break;
            }
            if (! is_string($name) || ! is_array($field)
                || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $name) !== 1) {
                continue;
            }
            $source = $field['source'] ?? null;
            $key = $field['key'] ?? null;
            if (! in_array($source, ['visibility_labels', 'search_attributes'], true)
                || ! is_string($key)) {
                continue;
            }
            $metadata = is_array($detail[$source] ?? null) ? $detail[$source] : [];
            $value = $metadata[$key] ?? null;
            $available = is_scalar($value) && (! is_float($value) || is_finite($value));
            $text = $available ? self::text($value) : null;
            $fields[] = [
                'name' => $name,
                'label' => is_string($field['label'] ?? null) ? self::text($field['label']) : $name,
                'value' => $text,
                'state' => $available ? 'available' : 'unavailable',
                'truncated' => $available && mb_strlen(self::text($value, null)) > self::VALUE_LIMIT,
            ];
            // Never construct a misleading entity URL from a truncated identifier.
            if ($available && $text !== '' && ! $fields[array_key_last($fields)]['truncated']) {
                $values[$name] = $text;
            }
        }

        $links = [];
        foreach (is_array($profile['links'] ?? null) ? $profile['links'] : [] as $name => $link) {
            if (count($links) >= self::LINK_LIMIT) {
                break;
            }
            if (! is_string($name) || ! is_array($link)) {
                continue;
            }
            $url = self::entityUrl($link['url'] ?? null, $values);
            if ($url !== null) {
                $links[] = [
                    'name' => $name,
                    'label' => is_string($link['label'] ?? null) ? self::text($link['label']) : $name,
                    'url' => $url,
                ];
            }
        }

        $detail['application_context'] = [
            'state' => $profile === [] ? 'not_configured' : 'configured',
            'fields' => $fields,
            'links' => $links,
        ];

        return $detail;
    }

    /** @param array<string, string> $values */
    private static function entityUrl(mixed $template, array $values): ?string
    {
        if (! is_string($template) || strlen($template) > 2048
            || preg_match('/[\x00-\x20\x7f\\\\]/', $template) === 1) {
            return null;
        }
        $parts = parse_url($template);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true)
            || ! is_string($parts['host'] ?? null) || strpbrk($parts['host'], '{}') !== false
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $missing = false;
        $url = preg_replace_callback('/\{([a-zA-Z][a-zA-Z0-9_]{0,63})\}/', static function (array $match) use ($values, &$missing): string {
            if (! array_key_exists($match[1], $values)) {
                $missing = true;

                return '';
            }

            return rawurlencode($values[$match[1]]);
        }, $template);

        return ! $missing && is_string($url) && strlen($url) <= 4096
            && strpbrk($url, '{}') === false && filter_var($url, FILTER_VALIDATE_URL) !== false
            ? $url : null;
    }

    private static function text(mixed $value, ?int $limit = self::VALUE_LIMIT): string
    {
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return $limit === null ? $text : mb_substr($text, 0, $limit);
    }
}
