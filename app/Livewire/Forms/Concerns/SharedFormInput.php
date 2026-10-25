<?php

namespace App\Livewire\Forms\Concerns;

use App\Exceptions\InsufficientSharesException;
use App\Exceptions\OptionPositionException;
use App\Models\Setting;
use App\Support\FixedDecimal;
use Closure;

/**
 * Checks and clean-up shared by the data-entry forms.
 */
trait SharedFormInput
{
    /**
     * The reporting start date as "YYYY-MM-DD".
     */
    private function reportingStartDate(): string
    {
        return Setting::current()->reporting_start_date->format('Y-m-d');
    }

    /**
     * A rule that fails when the value is zero. Use after "bail" and the
     * matching format rule, so it only sees well-formed numbers.
     *
     * @param  class-string<FixedDecimal>  $valueClass
     */
    private function greaterThanZero(string $valueClass, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($valueClass, $message): void {
            if ($valueClass::fromDecimal((string) $value)->isZero()) {
                $fail($message);
            }
        };
    }

    /**
     * The field of an option position form that a refused correction belongs to.
     */
    private function positionErrorField(OptionPositionException|InsufficientSharesException $exception): string
    {
        if (! $exception instanceof OptionPositionException) {
            return 'contracts';
        }

        return match ($exception->field) {
            'event_date' => 'expires_on',
            'option_type' => 'option_type',
            default => 'contracts',
        };
    }

    private function normalizedTicker(string $ticker): string
    {
        return strtoupper(trim($ticker));
    }

    private function nullIfBlank(string $value): ?string
    {
        return trim($value) !== '' ? trim($value) : null;
    }
}
