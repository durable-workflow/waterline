<?php

declare(strict_types=1);

namespace Waterline\Support;

final class WorkerRegistrationRoster
{
    /**
     * Build the backend-independent worker health roster.
     *
     * The general registration input may include stale rows. The returned
     * active and stale rosters are disjoint, and a status-specific stale
     * observation wins when the same worker appears in both inputs.
     *
     * @param  list<array<string, mixed>>  $registrations
     * @param  list<array<string, mixed>>  $stale
     * @return array{
     *     registration_count: int,
     *     active_registration_count: int,
     *     stale_registration_count: int,
     *     registrations: list<array<string, mixed>>,
     *     stale_registrations: list<array<string, mixed>>
     * }
     */
    public static function from(array $registrations, array $stale): array
    {
        $activeByIdentity = self::indexByIdentity($registrations);
        $staleByIdentity = self::indexByIdentity(array_map(
            static fn (array $registration): array => [
                ...$registration,
                'status' => 'stale',
            ],
            $stale,
        ));

        foreach ($activeByIdentity as $identity => $registration) {
            if (($registration['status'] ?? null) !== 'stale') {
                continue;
            }

            $staleByIdentity[$identity] ??= [
                ...$registration,
                'status' => 'stale',
            ];
            unset($activeByIdentity[$identity]);
        }

        foreach (array_keys($staleByIdentity) as $identity) {
            unset($activeByIdentity[$identity]);
        }

        $activeRegistrations = array_values($activeByIdentity);
        $staleRegistrations = array_values($staleByIdentity);

        return [
            'registration_count' => count($activeRegistrations) + count($staleRegistrations),
            'active_registration_count' => count($activeRegistrations),
            'stale_registration_count' => count($staleRegistrations),
            'registrations' => $activeRegistrations,
            'stale_registrations' => $staleRegistrations,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $registrations
     * @return array<string, array<string, mixed>>
     */
    private static function indexByIdentity(array $registrations): array
    {
        $indexed = [];

        foreach ($registrations as $registration) {
            $identity = implode('|', [
                (string) ($registration['worker_id'] ?? ''),
                (string) ($registration['namespace'] ?? ''),
            ]);
            $indexed[$identity] = $registration;
        }

        return $indexed;
    }
}
