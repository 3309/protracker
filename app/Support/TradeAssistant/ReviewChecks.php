<?php

namespace App\Support\TradeAssistant;

use App\Actions\Cash\BuildCashLedger;
use App\Enums\OptionType;
use App\Livewire\Forms\SellToOpenForm;
use App\Models\Account;
use App\Models\OptionPosition;
use App\Support\InputFormat;
use App\Support\MarketCalendar;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Totals and warnings for the AI Trade Assistant's review screen, worked
 * out from the values as they stand in the form. Warnings never block a
 * save; the entry forms' own checks decide what can be saved.
 */
class ReviewChecks
{
    /**
     * Price × shares for stock, or premium × contracts × shares per contract
     * for options. Null while the numbers are missing or not valid.
     */
    public static function grossAmount(string $perShare, string $quantity, ?string $multiplier = null): ?Money
    {
        $perShare = str_replace('$', '', trim($perShare));

        if (preg_match(InputFormat::PRICE, $perShare) !== 1) {
            return null;
        }

        try {
            $shares = $multiplier === null
                ? (preg_match(InputFormat::SHARES, trim($quantity)) === 1 ? Quantity::fromDecimal(trim($quantity)) : null)
                : (preg_match('/^\d{1,6}$/', trim($quantity)) === 1 && preg_match('/^\d{1,6}$/', trim($multiplier)) === 1
                    ? Quantity::fromInteger((int) $quantity * (int) $multiplier)
                    : null);

            return $shares !== null ? UnitPrice::fromDecimal($perShare)->totalFor($shares) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Warnings shared by options sold and bought to open.
     *
     * @param  string  $multiplierStatus  the proposal's status for shares per contract; when it was not given, 100 is filled in
     * @return list<string>
     */
    public static function forOption(SellToOpenForm $form, string $multiplierStatus, ?Account $account, CarbonImmutable $today): array
    {
        return array_values(array_filter([
            self::futureDate($form->opened_on, $today, 'trade date'),
            self::expirationNotTradingDay($form->expires_on),
            in_array($multiplierStatus, ['missing', 'ambiguous'], true) && trim($form->multiplier) === '100'
                ? 'Shares per contract was not given, so the standard 100 is filled in. Change it if this is an adjusted or non-standard contract.'
                : null,
            $account !== null && trim($form->opening_fees) === '' && preg_match('/^\d{1,6}$/', trim($form->contracts)) === 1
                ? "No fee was given, so the account's default of {$account->option_fee_per_contract->format()} per contract ({$account->option_fee_per_contract->times((int) $form->contracts)->format()}) will be used."
                : null,
        ]));
    }

    public static function futureDate(string $date, CarbonImmutable $today, string $label): ?string
    {
        $parsed = self::date($date);

        return $parsed !== null && $parsed->greaterThan($today)
            ? "The {$label} {$parsed->format('M j, Y')} is in the future. Check it before saving."
            : null;
    }

    /**
     * Options normally expire on a trading day; a weekend or market holiday
     * usually means the date was misread. The date is never changed.
     */
    public static function expirationNotTradingDay(string $expiresOn): ?string
    {
        $parsed = self::date($expiresOn);

        if ($parsed === null || MarketCalendar::isTradingDay($parsed)) {
            return null;
        }

        return sprintf(
            'The expiration date %s is a %s, when the market is closed. Options normally expire on a trading day; please check the date.',
            $parsed->format('M j, Y'),
            $parsed->isWeekend() ? $parsed->format('l') : 'market holiday',
        );
    }

    /**
     * For a sold put: whether the account's cash on the trade date covers
     * this put's strike value plus that of the account's other open sold
     * puts. The tracker does not require this, so it is only a warning.
     */
    public static function cashSecuredPut(string $accountId, string $optionType, string $openedOn, string $strike, string $contracts, string $multiplier): ?string
    {
        $date = self::date($openedOn);
        $collateral = self::grossAmount($strike, $contracts, $multiplier);

        if ($optionType !== OptionType::Put->value || ! ctype_digit($accountId) || $date === null || $collateral === null) {
            return null;
        }

        $otherPuts = OptionPosition::query()
            ->open()
            ->short()
            ->notVoided()
            ->where('account_id', (int) $accountId)
            ->where('option_type', OptionType::Put)
            ->get()
            ->reduce(
                fn (Money $sum, OptionPosition $position): Money => $sum->plus($position->strike->totalFor(Quantity::fromInteger($position->openContracts() * $position->multiplier))),
                Money::zero(),
            );

        $cash = app(BuildCashLedger::class)->handle((int) $accountId)->totalOn($date);
        $needed = $collateral->plus($otherPuts);

        if (! $cash->minus($needed)->isNegative()) {
            return null;
        }

        return sprintf(
            'Cash in this account on %s is %s, but this put and the account\'s other open sold puts could need %s to buy the shares if assigned. You can still save it.',
            $date->format('M j, Y'),
            $cash->format(),
            $needed->format(),
        );
    }

    private static function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
