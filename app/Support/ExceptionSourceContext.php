<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Http\Request;
use Throwable;
use Waterline\Waterline;

/** Local source is presentation data for one authorized embedded detail response. */
final class ExceptionSourceContext
{
    private const MAX_FILES = 20;
    private const MAX_FILE_BYTES = 1048576;
    private const MAX_READ_BYTES = 2097152;
    private const MAX_LINE_BYTES = 4096;
    private const MAX_EXCERPT_BYTES = 16384;
    private const MAX_RESPONSE_BYTES = 65536;

    private array $roots = [];
    private int $files = 0;
    private int $readBytes = 0;
    private int $responseBytes = 0;

    private function __construct()
    {
        $extra = config('waterline.source_context.additional_roots', []);
        foreach ([app_path(), ...(is_array($extra) ? $extra : [])] as $root) {
            if (! is_string($root) || $root === '' || str_contains($root, "\0")) {
                continue;
            }
            $canonical = realpath($root);
            if ($canonical !== false && is_dir($canonical)) {
                $this->roots[] = rtrim($canonical, '/\\').DIRECTORY_SEPARATOR;
            }
        }
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public static function annotate(array $detail, Request $request): array
    {
        $reason = self::unavailableReason($request);
        $reader = $reason === null ? new self() : null;
        $exceptions = $detail['exceptions'] ?? [];
        if (! is_iterable($exceptions)) {
            return $detail;
        }
        $rows = [];
        foreach ($exceptions as $exception) {
            if (! is_array($exception)) {
                continue;
            }
            $payload = is_array($exception['exception'] ?? null) ? $exception['exception'] : [];
            $exception['trace_preview'] = $exception['trace_preview'] ?? $exception['code'] ?? null;
            // Keep the old trace alias for existing API consumers. Source is
            // never put into code, tooltips, history or diagnostic projections.
            $exception['source_context'] = $reader === null
                ? self::unavailable($reason)
                : $reader->excerpt($payload['file'] ?? null, $payload['line'] ?? null);
            $rows[] = $exception;
        }
        $detail['exceptions'] = $rows;

        return $detail;
    }

    private static function unavailableReason(Request $request): ?string
    {
        if (BackendConfiguration::serviceMode()) {
            return 'unsupported_mode';
        }
        if (! filter_var(config('waterline.source_context.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return 'disabled';
        }
        if (Waterline::allowsUnauthenticatedAccess() || ! Waterline::check($request)) {
            return 'unauthenticated';
        }

        return null;
    }

    private static function unavailable(?string $reason = 'source_unavailable'): array
    {
        return ['status' => 'unavailable', 'reason' => $reason,
            'excerpt' => null, 'start_line' => null, 'throw_line' => null];
    }

    private function excerpt(mixed $path, mixed $line): array
    {
        if (++$this->files > self::MAX_FILES || $this->readBytes >= self::MAX_READ_BYTES
            || $this->responseBytes >= self::MAX_RESPONSE_BYTES) {
            return self::unavailable('response_budget_exhausted');
        }
        $candidate = $this->candidate($path, $line);
        if ($candidate === null) {
            return self::unavailable();
        }
        [$canonical, $before] = $candidate;
        $this->readBytes += $before['size'];
        $handle = @fopen($canonical, 'rb');
        if ($handle === false) {
            return self::unavailable();
        }
        try {
            if (! $this->sameFile($before, fstat($handle)) || realpath($canonical) !== $canonical) {
                return self::unavailable();
            }
            $source = $this->readExcerpt($handle, $line, $before['size']);
            clearstatcache(true, $canonical);
            if ($source === null || ! $this->sameFile($before, fstat($handle))
                || ! $this->sameFile($before, @lstat($canonical)) || realpath($canonical) !== $canonical) {
                return self::unavailable();
            }
            $bytes = strlen($source['excerpt']);
            if ($bytes > self::MAX_RESPONSE_BYTES - $this->responseBytes) {
                return self::unavailable('response_budget_exhausted');
            }
            $this->responseBytes += $bytes;

            return array_merge(['status' => 'available', 'reason' => null], $source, ['throw_line' => $line]);
        } catch (Throwable) {
            return self::unavailable();
        } finally {
            fclose($handle);
        }
    }

    private function candidate(mixed $path, mixed $line): ?array
    {
        if (! is_string($path) || ! is_int($line) || $line < 1 || str_contains($path, "\0")
            || ! preg_match('~^(?:/|[a-zA-Z]:[\\\\/])~', $path)
            || preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $path)) {
            return null;
        }
        $canonical = realpath($path);
        if ($canonical === false || ! $this->allowedPath($canonical)) {
            return null;
        }
        clearstatcache(true, $canonical);
        $before = @lstat($canonical);
        if (! $this->regularFile($before) || ! is_readable($canonical)
            || $before['size'] > self::MAX_FILE_BYTES || $before['size'] > self::MAX_READ_BYTES - $this->readBytes
            || $line > $before['size'] + 1) {
            return null;
        }

        return [$canonical, $before];
    }

    private function allowedPath(string $path): bool
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            return false;
        }
        foreach ($this->roots as $root) {
            if (str_starts_with($path, $root)) {
                return true;
            }
        }

        return false;
    }

    private function regularFile(mixed $metadata): bool
    {
        return is_array($metadata) && ($metadata['mode'] & 0170000) === 0100000;
    }

    private function sameFile(array $before, mixed $after): bool
    {
        return $this->regularFile($after)
            && array_intersect_key($before, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']))
                === array_intersect_key($after, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    /** @param resource $handle @return array{excerpt: string, start_line: int}|null */
    private function readExcerpt($handle, int $throwLine, int $size): ?array
    {
        $start = max(1, $throwLine - 3);
        $end = $throwLine + 3;
        $lines = [];
        $line = 0;
        $bytes = 0;
        while (($text = fgets($handle, self::MAX_LINE_BYTES + 2)) !== false) {
            ++$line;
            $bytes += strlen($text);
            if ($bytes > $size || strlen($text) > self::MAX_LINE_BYTES
                || str_contains($text, "\0") || preg_match('//u', $text) !== 1) {
                return null;
            }
            if ($line >= $start && $line <= $end) {
                $lines[] = rtrim($text, "\r\n");
            }
        }
        $excerpt = implode("\n", $lines);

        return $line >= $throwLine && strlen($excerpt) <= self::MAX_EXCERPT_BYTES
            ? ['excerpt' => $excerpt, 'start_line' => $start] : null;
    }
}
