<?php

namespace App\Support\TradeAssistant;

use App\Models\Account;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * What the AI is told besides the description: today's date in the
 * application's time zone, so "today" and "yesterday" can be worked out,
 * and the active accounts by name. No balances, positions or account
 * numbers are included.
 */
final readonly class TradeContext
{
    /**
     * @param  array<int, string>  $accounts  account id => name, e.g. "Fidelity – Roth IRA"
     */
    public function __construct(
        public CarbonImmutable $today,
        public string $timezone,
        public array $accounts,
    ) {}

    public static function current(): self
    {
        $timezone = Setting::current()->display_timezone;

        return new self(
            CarbonImmutable::parse(CarbonImmutable::now($timezone)->format('Y-m-d')),
            $timezone,
            Account::query()->active()->orderBy('nickname')->get()
                ->mapWithKeys(fn (Account $account): array => [$account->id => $account->displayName()])
                ->all(),
        );
    }
}
