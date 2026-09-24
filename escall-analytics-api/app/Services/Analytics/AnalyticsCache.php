<?php

namespace App\Services\Analytics;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class AnalyticsCache
{
    private const VERSION_KEY = 'analytics:cache-version';

    /** @template T
     * @param  array<string, mixed>  $filters
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(string $scope, array $filters, int $seconds, Closure $callback): mixed
    {
        return Cache::remember(
            $this->key($scope, $filters),
            now()->addSeconds($seconds),
            $callback,
        );
    }

    public function invalidate(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    /** @param array<string, mixed> $filters */
    private function key(string $scope, array $filters): string
    {
        return sprintf(
            'analytics:%s:%s:%s',
            $this->version(),
            $scope,
            hash('sha256', json_encode($this->normalize($filters), JSON_THROW_ON_ERROR)),
        );
    }

    private function version(): string
    {
        return (string) Cache::rememberForever(self::VERSION_KEY, static fn (): string => (string) Str::uuid());
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        if (array_is_list($value)) {
            sort($value, SORT_STRING);
        } else {
            ksort($value);
        }

        return $value;
    }
}
