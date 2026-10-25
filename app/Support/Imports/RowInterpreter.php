<?php

namespace App\Support\Imports;

use App\Enums\ImportAction;
use App\Enums\ImportRowStatus;
use App\Support\DividendRate;
use App\Support\InputFormat;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Checks the common fields of an import row for its action: that required
 * values are present and well formed, and that a stated amount agrees with
 * price × quantity and fees. Also builds the row's duplicate fingerprint.
 */
final class RowInterpreter
{
    /**
     * @param  array<string, string>  $data
     * @return array{status: ImportRowStatus, messages: list<string>}
     */
    public static function check(?ImportAction $action, array $data, string $reportingStartDate): array
    {
        if ($action === null) {
            return ['status' => ImportRowStatus::Unrecognized, 'messages' => []];
        }

        $missing = [];
        $invalid = [];
        $value = fn (string $field): string => trim($data[$field] ?? '');

        $required = match (true) {
            $action->isStockTrade() => ['date', 'symbol', 'quantity', 'price'],
            $action === ImportAction::SellToOpen, $action === ImportAction::BuyToClose => ['date', 'symbol', 'option_type', 'strike', 'expiration', 'quantity', 'price'],
            $action->closesOption() => ['date', 'symbol', 'option_type', 'strike', 'expiration', 'quantity'],
            $action === ImportAction::Dividend => ['date', 'symbol', 'amount', 'ex_date'],
            default => ['date', 'amount'],
        };

        foreach ($required as $field) {
            if ($value($field) === '') {
                $missing[] = $field;
            }
        }

        foreach (['date', 'expiration', 'settlement_date', 'ex_date'] as $field) {
            if ($value($field) !== '' && ! self::isDate($value($field))) {
                $invalid[] = "{$field} \"{$value($field)}\" is not a date (use YYYY-MM-DD)";
            }
        }

        if (self::isDate($value('date')) && $value('date') < $reportingStartDate) {
            $invalid[] = "the date is before the reporting start date ({$reportingStartDate}); enter earlier holdings as opening balances";
        }

        if ($value('symbol') !== '' && preg_match(InputFormat::TICKER, $value('symbol')) !== 1) {
            $invalid[] = "symbol \"{$value('symbol')}\" is not a ticker";
        }

        if ($value('quantity') !== '') {
            $quantity = ltrim($value('quantity'), '-');
            $wholeContracts = $action->isOptionContract() ? preg_match('/^\d{1,6}$/', $quantity) === 1 && (int) $quantity > 0 : null;

            if ($action->isOptionContract() && ! $wholeContracts) {
                $invalid[] = 'quantity must be a whole number of contracts';
            } elseif (! $action->isOptionContract() && (preg_match(InputFormat::SHARES, $quantity) !== 1 || Quantity::fromDecimal($quantity)->isZero())) {
                $invalid[] = 'quantity must be a number of shares above zero, with no more than six decimal places';
            }
        }

        if ($value('price') !== '') {
            $pricePattern = $action === ImportAction::Dividend ? InputFormat::DIVIDEND_RATE : InputFormat::PRICE;

            if (preg_match($pricePattern, $value('price')) !== 1 || ($action !== ImportAction::Dividend && UnitPrice::fromDecimal($value('price'))->isZero())) {
                $invalid[] = 'price is not a positive amount per share';
            }
        }

        if ($value('strike') !== '' && (preg_match(InputFormat::PRICE, $value('strike')) !== 1 || UnitPrice::fromDecimal($value('strike'))->isZero())) {
            $invalid[] = 'strike is not a positive price';
        }

        if ($value('option_type') !== '' && ! in_array($value('option_type'), ['put', 'call'], true)) {
            $invalid[] = "option type \"{$value('option_type')}\" is not put or call";
        }

        if ($value('fees') !== '' && preg_match(InputFormat::FEE, ltrim($value('fees'), '-')) !== 1) {
            $invalid[] = 'fees is not a dollar amount';
        }

        if ($value('amount') !== '' && preg_match(InputFormat::SIGNED_MONEY, $value('amount')) !== 1) {
            $invalid[] = 'amount is not a dollar amount with at most two decimal places';
        }

        if ($value('multiplier') !== '' && (preg_match('/^\d{1,6}$/', $value('multiplier')) !== 1 || (int) $value('multiplier') === 0)) {
            $invalid[] = 'multiplier must be a whole number above zero';
        }

        if ($missing !== []) {
            $messages = ['Missing: '.implode(', ', $missing).'.'];

            if ($missing === ['ex_date']) {
                $messages = ['Needs the ex-dividend date, which the file does not give. Enter it to import this dividend.'];
            }

            return ['status' => ImportRowStatus::Incomplete, 'messages' => [...$messages, ...array_map(fn ($message) => ucfirst($message).'.', $invalid)]];
        }

        if ($invalid !== []) {
            return ['status' => ImportRowStatus::Invalid, 'messages' => array_map(fn ($message) => ucfirst($message).'.', $invalid)];
        }

        $amountMismatch = self::amountMismatch($action, $data);

        if ($amountMismatch !== null) {
            return ['status' => ImportRowStatus::Ambiguous, 'messages' => [$amountMismatch]];
        }

        return ['status' => ImportRowStatus::Recognized, 'messages' => []];
    }

    /**
     * A fingerprint of the row's meaning: account, date, action, contract,
     * quantity, price, amount and fees. Formatting differences (such as
     * "$1,000" and "1000.00") give the same fingerprint.
     *
     * @param  array<string, string>  $data
     */
    public static function fingerprint(int $accountId, ImportAction $action, array $data): string
    {
        $value = fn (string $field): string => trim($data[$field] ?? '');
        $canonical = fn (string $field, callable $parse): string => self::canonical($value($field), $parse);

        return hash('sha256', implode('|', [
            $accountId,
            $action->value,
            $value('date'),
            $value('symbol'),
            $value('option_type'),
            $canonical('strike', fn (string $v) => UnitPrice::fromDecimal($v)->units),
            $value('expiration'),
            $canonical('quantity', fn (string $v) => Quantity::fromDecimal(ltrim($v, '-'))->units),
            $canonical('price', fn (string $v) => DividendRate::fromDecimal($v)->units),
            $canonical('amount', fn (string $v) => abs(Money::fromDecimal($v)->cents)),
            $canonical('fees', fn (string $v) => abs(Money::fromDecimal($v)->cents)),
        ]));
    }

    /**
     * The stated amount must equal price × quantity × multiplier, less fees
     * for money received or plus fees for money paid (either sign accepted).
     *
     * @param  array<string, string>  $data
     */
    private static function amountMismatch(ImportAction $action, array $data): ?string
    {
        $amount = trim($data['amount'] ?? '');

        if ($amount === '' || ! ($action->isStockTrade() || $action === ImportAction::SellToOpen || $action === ImportAction::BuyToClose)) {
            return null;
        }

        $shares = $action->isStockTrade()
            ? Quantity::fromDecimal(ltrim($data['quantity'], '-'))
            : Quantity::fromInteger((int) ltrim($data['quantity'], '-') * ((int) ($data['multiplier'] ?? '') ?: 100));
        $gross = UnitPrice::fromDecimal($data['price'])->totalFor($shares);
        $fees = trim($data['fees'] ?? '') !== '' ? Money::fromCents(abs(Money::fromDecimal($data['fees'])->cents)) : null;

        if ($fees === null) {
            return null;
        }

        $receivesMoney = $action === ImportAction::Sell || $action === ImportAction::SellToOpen;
        $expected = $receivesMoney ? $gross->minus($fees) : $gross->plus($fees);

        if (abs(Money::fromDecimal($amount)->cents) === $expected->cents) {
            return null;
        }

        return "The amount {$amount} does not equal price × quantity ".($receivesMoney ? 'less' : 'plus')." fees ({$expected->format()}). Check the row, or choose to import it anyway using price, quantity and fees.";
    }

    /**
     * @param  callable(string): int  $parse
     */
    private static function canonical(string $value, callable $parse): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return (string) $parse($value);
        } catch (InvalidArgumentException) {
            return $value;
        }
    }

    private static function isDate(string $value): bool
    {
        $date = CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d') ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;

        return $date !== null && $date->format('Y-m-d') === $value;
    }
}
