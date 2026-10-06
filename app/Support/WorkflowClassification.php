<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorkflowClassification
{
    public static function forType(mixed $type): ?string
    {
        return is_string($type) ? (self::typeMap()[$type] ?? null) : null;
    }

    /** @return array<string, mixed> */
    public static function selection(Request $request): array
    {
        $validated = $request->validate([
            'classification' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/D'],
        ]);
        $selected = $validated['classification'] ?? null;
        $groups = [];
        foreach (self::typeMap() as $type => $classification) {
            $groups[$classification][] = $type;
        }
        ksort($groups);
        foreach ($groups as &$types) {
            sort($types, SORT_STRING);
        }
        unset($types);
        if ($selected !== null && ! isset($groups[$selected])) {
            throw ValidationException::withMessages([
                'classification' => 'Choose a classification configured by this application.',
            ]);
        }

        return [
            'classification' => $selected,
            'label' => $selected === null ? 'All workflow types' : self::label($selected),
            'workflow_types' => $selected === null ? null : $groups[$selected],
            'options' => array_map(static fn (string $classification): array => [
                'value' => $classification,
                'label' => self::label($classification),
            ], array_keys($groups)),
        ];
    }

    /** @return array<string, string> */
    private static function typeMap(): array
    {
        $profiles = config('waterline.observability.workflow_types', []);
        $map = [];
        foreach (is_array($profiles) ? $profiles : [] as $type => $profile) {
            $classification = is_array($profile) ? ($profile['classification'] ?? null) : null;
            if (is_string($type) && $type !== '' && is_string($classification)
                && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $classification) === 1) {
                $map[$type] = $classification;
            }
        }

        return $map;
    }

    private static function label(string $classification): string
    {
        return ucwords(str_replace('_', ' ', $classification));
    }
}
