<?php

namespace App\Support\Alpaca;

use App\Exceptions\AlpacaServiceException;
use Carbon\CarbonImmutable;
use Closure;

/**
 * One answer from the local Alpaca service, or the reason there is none, so a
 * page can tell "unavailable" apart from "available, and empty".
 */
final readonly class AlpacaResult
{
    /**
     * @param  array<mixed>  $data
     * @param  array<mixed>  $meta
     */
    public function __construct(
        public array $data = [],
        public array $meta = [],
        public ?CarbonImmutable $fetchedAt = null,
        public bool $fromCache = false,
        public ?string $unavailableReason = null,
    ) {}

    public static function unavailable(string $reason): self
    {
        return new self(unavailableReason: $reason);
    }

    /**
     * Run a fetch, turning a service failure into an unavailable result.
     *
     * @param  Closure(): self  $fetch
     */
    public static function attempt(Closure $fetch): self
    {
        try {
            return $fetch();
        } catch (AlpacaServiceException $exception) {
            return self::unavailable($exception->getMessage());
        }
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason === null;
    }

    public function isEmpty(): bool
    {
        return $this->isAvailable() && $this->data === [];
    }

    /**
     * Plain values for the cache, which does not store PHP objects.
     *
     * @return array{data: array<mixed>, meta: array<mixed>, fetched_at: string|null}
     */
    public function toCache(): array
    {
        return ['data' => $this->data, 'meta' => $this->meta, 'fetched_at' => $this->fetchedAt?->toIso8601String()];
    }

    /**
     * The saved answer, or null when what was saved is not one.
     *
     * @param  array<mixed>  $cached
     */
    public static function fromCache(array $cached): ?self
    {
        if (! is_array($cached['data'] ?? null) || ! is_array($cached['meta'] ?? null) || ! is_string($cached['fetched_at'] ?? null)) {
            return null;
        }

        return new self($cached['data'], $cached['meta'], CarbonImmutable::parse($cached['fetched_at']), fromCache: true);
    }
}
