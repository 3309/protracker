<?php

namespace App\Support;

use App\Actions\Cash\BuildCashLedger;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Enums\OpeningStatus;
use App\Models\Account;
use App\Models\BrokerBalance;
use App\Models\Dividend;
use App\Models\ImportRow;
use App\Models\OptionPosition;
use Carbon\CarbonImmutable;

/**
 * Things that need attention: missing or stale prices, options past
 * expiration, unconfirmed or differing dividends, cash that does not match
 * the broker, and incomplete opening balances.
 */
final class DataQualityAlerts
{
    /**
     * @param  list<int>|null  $accountIds  null for all accounts
     * @return list<array{message: string, url: string}>
     */
    public static function collect(OpenPositionsReport $open, CarbonImmutable $today, ?array $accountIds): array
    {
        return [
            ...self::priceAlerts($open),
            ...self::expirationAlerts($today, $accountIds),
            ...self::dividendAlerts($today, $accountIds),
            ...self::reconciliationAlerts($today, $accountIds),
            ...self::openingBalanceAlerts($accountIds),
            ...self::importAlerts($accountIds),
        ];
    }

    /**
     * Rows of imported files that were not imported and need a correction
     * or decision, including possible duplicates.
     *
     * @param  list<int>|null  $accountIds
     * @return list<array{message: string, url: string}>
     */
    private static function importAlerts(?array $accountIds): array
    {
        $waiting = ImportRow::query()
            ->whereIn('status', array_filter(ImportRowStatus::cases(), fn (ImportRowStatus $status) => $status->needsReview()))
            ->whereHas('batch', fn ($batch) => $batch
                ->where('status', ImportBatchStatus::Committed)
                ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds)))
            ->count();

        return $waiting === 0 ? [] : [[
            'message' => "{$waiting} imported ".($waiting === 1 ? 'row needs review before it' : 'rows need review before they').' can be imported or skipped.',
            'url' => route('imports.review'),
        ]];
    }

    /**
     * @return list<array{message: string, url: string}>
     */
    private static function priceAlerts(OpenPositionsReport $open): array
    {
        $alerts = [];
        $url = route('prices.index');

        foreach ($open->stocks->unique('ticker') as $stock) {
            if ($stock['price'] === null) {
                $alerts[] = ['message' => "{$stock['ticker']}: price needed. Unrealized P/L is not shown until a price is entered.", 'url' => $url];
            } elseif ($stock['stale']) {
                $alerts[] = ['message' => "{$stock['ticker']}: the latest price is stale ({$open->prices->ageInDays($stock['price'])} days old).", 'url' => $url];
            }
        }

        foreach ($open->options as $option) {
            $description = $option['position']->description();

            if ($option['price'] === null) {
                $alerts[] = ['message' => "{$description}: option price needed.", 'url' => $url];
            } elseif ($option['stale']) {
                $alerts[] = ['message' => "{$description}: the latest option price is stale ({$open->prices->ageInDays($option['price'])} days old).", 'url' => $url];
            }
        }

        return $alerts;
    }

    /**
     * @param  list<int>|null  $accountIds
     * @return list<array{message: string, url: string}>
     */
    private static function expirationAlerts(CarbonImmutable $today, ?array $accountIds): array
    {
        return array_values(OptionPosition::query()
            ->notVoided()
            ->open()
            ->where('expires_on', '<', $today->format('Y-m-d'))
            ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
            ->orderBy('expires_on')
            ->get()
            ->map(fn (OptionPosition $position) => [
                'message' => $position->isLong()
                    ? "{$position->description()} is past expiration. Record whether it expired worthless or was sold to close."
                    : "{$position->description()} is past expiration. Record whether it expired worthless or was assigned.",
                'url' => route('options.show', $position),
            ])
            ->all());
    }

    /**
     * @param  list<int>|null  $accountIds
     * @return list<array{message: string, url: string}>
     */
    private static function dividendAlerts(CarbonImmutable $today, ?array $accountIds): array
    {
        $alerts = [];

        $dividends = Dividend::query()
            ->notVoided()
            ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
            ->orderBy('pay_date')
            ->get();

        foreach ($dividends as $dividend) {
            $url = route('dividends.edit', $dividend);
            $label = "{$dividend->ticker} dividend paid {$dividend->pay_date->format('M j, Y')}";

            if (! $dividend->isConfirmed()) {
                if ($dividend->pay_date->format('Y-m-d') < $today->format('Y-m-d')) {
                    $alerts[] = ['message' => "{$label} has not been confirmed.", 'url' => $url];
                }

                continue;
            }

            if ($dividend->hasDifference()) {
                $alerts[] = ['message' => "{$label}: the amount received differs from the expected amount by {$dividend->difference()?->format()}.", 'url' => $url];
            }

            if ($dividend->eligibleSharesChangedSinceConfirmation()) {
                $alerts[] = ['message' => "{$label}: the shares held on the ex-date have changed since it was confirmed.", 'url' => $url];
            }
        }

        return $alerts;
    }

    /**
     * Compares the latest broker balance of each account with the ledger.
     *
     * @param  list<int>|null  $accountIds
     * @return list<array{message: string, url: string}>
     */
    private static function reconciliationAlerts(CarbonImmutable $today, ?array $accountIds): array
    {
        $alerts = [];

        $latestBalances = BrokerBalance::query()
            ->with('account')
            ->notVoided()
            ->where('as_of_date', '<=', $today->format('Y-m-d'))
            ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
            ->orderBy('as_of_date')
            ->orderBy('id')
            ->get()
            ->keyBy('account_id');

        foreach ($latestBalances as $balance) {
            $ledger = app(BuildCashLedger::class)->handle($balance->account_id);
            $difference = $balance->total_cash->minus($ledger->totalOn($balance->as_of_date));
            $settledDifference = $balance->settled_cash?->minus($ledger->settledOn($balance->as_of_date));

            if (! $difference->isZero() || ($settledDifference !== null && ! $settledDifference->isZero())) {
                $alerts[] = [
                    'message' => "{$balance->account->displayName()}: cash on {$balance->as_of_date->format('M j, Y')} differs from the broker by {$difference->format()}.",
                    'url' => route('cash.reconciliation', ['account' => $balance->account_id]),
                ];
            }
        }

        return $alerts;
    }

    /**
     * @param  list<int>|null  $accountIds
     * @return list<array{message: string, url: string}>
     */
    private static function openingBalanceAlerts(?array $accountIds): array
    {
        return array_values(Account::query()
            ->active()
            ->withOpeningProgress()
            ->when($accountIds !== null, fn ($query) => $query->whereIn('id', $accountIds))
            ->orderBy('nickname')
            ->get()
            ->filter(fn (Account $account) => $account->openingStatus() !== OpeningStatus::Complete)
            ->map(fn (Account $account) => [
                'message' => "Opening balances for {$account->displayName()} are not marked complete.",
                'url' => route('opening-balances.show', $account),
            ])
            ->all());
    }
}
