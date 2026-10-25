<?php

namespace App\Support\Imports;

use App\Enums\ImportAction;
use Carbon\CarbonImmutable;

/**
 * The Protracker template: a simple CSV layout whose columns match the
 * common import fields.
 *
 * Columns: date, action, symbol, quantity, price, fees, amount, option_type,
 * strike, expiration (required in the header), and optionally multiplier,
 * settlement_date, ex_date, transaction_id and description.
 *
 * Dates are YYYY-MM-DD (MM/DD/YYYY is also understood). Amounts may use $,
 * thousands separators and (1.23) for negatives.
 */
final class TemplateFormat implements ImportFormat
{
    public const array REQUIRED_COLUMNS = ['date', 'action', 'symbol', 'quantity', 'price', 'fees', 'amount', 'option_type', 'strike', 'expiration'];

    public const array OPTIONAL_COLUMNS = ['multiplier', 'settlement_date', 'ex_date', 'transaction_id', 'description'];

    /**
     * Action words understood, after upper-casing and turning spaces and
     * hyphens into underscores.
     */
    private const array ACTIONS = [
        'BUY' => ImportAction::Buy,
        'SELL' => ImportAction::Sell,
        'SELL_TO_OPEN' => ImportAction::SellToOpen,
        'STO' => ImportAction::SellToOpen,
        'BUY_TO_CLOSE' => ImportAction::BuyToClose,
        'BTC' => ImportAction::BuyToClose,
        'EXPIRED' => ImportAction::Expired,
        'ASSIGNED' => ImportAction::Assigned,
        'DIVIDEND' => ImportAction::Dividend,
        'DEPOSIT' => ImportAction::Deposit,
        'WITHDRAWAL' => ImportAction::Withdrawal,
        'INTEREST' => ImportAction::Interest,
        'OTHER_CREDIT' => ImportAction::OtherCredit,
        'OTHER_CHARGE' => ImportAction::OtherCharge,
    ];

    public function missingColumns(array $header): array
    {
        $present = array_map(fn (string $column) => strtolower(trim($column)), $header);

        return array_values(array_diff(self::REQUIRED_COLUMNS, $present));
    }

    public function normalize(array $raw): array
    {
        $values = [];

        foreach ($raw as $column => $value) {
            $values[strtolower(trim($column))] = trim($value);
        }

        $messages = [];
        $actionWord = strtoupper((string) preg_replace('/[\s\-]+/', '_', $values['action'] ?? ''));
        $action = self::ACTIONS[$actionWord] ?? null;

        if ($action === null) {
            $messages[] = $actionWord === ''
                ? 'The action is blank.'
                : "The action \"{$values['action']}\" is not one this format understands. It was not guessed.";
        }

        $data = [
            'date' => self::date($values['date'] ?? ''),
            'action' => $values['action'] ?? '',
            'symbol' => strtoupper($values['symbol'] ?? ''),
            'quantity' => self::number($values['quantity'] ?? ''),
            'price' => self::number($values['price'] ?? ''),
            'fees' => self::number($values['fees'] ?? ''),
            'amount' => self::number($values['amount'] ?? ''),
            'option_type' => self::optionType($values['option_type'] ?? ''),
            'strike' => self::number($values['strike'] ?? ''),
            'expiration' => self::date($values['expiration'] ?? ''),
            'multiplier' => self::number($values['multiplier'] ?? ''),
            'settlement_date' => self::date($values['settlement_date'] ?? ''),
            'ex_date' => self::date($values['ex_date'] ?? ''),
            'transaction_id' => $values['transaction_id'] ?? '',
            'description' => $values['description'] ?? '',
        ];

        return ['action' => $action, 'data' => $data, 'messages' => $messages];
    }

    /**
     * Template rows say exactly what they are, so nothing changes.
     */
    public function combine(array $rows): array
    {
        return $rows;
    }

    /**
     * "$1,234.50" → "1234.50", "(0.65)" → "-0.65". Anything else is left as
     * it is so the row checker can report it.
     */
    private static function number(string $value): string
    {
        $value = str_replace(['$', ',', ' '], '', trim($value));

        if (preg_match('/^\((.+)\)$/', $value, $matches) === 1) {
            return '-'.$matches[1];
        }

        return $value;
    }

    /**
     * "2026-03-02" or "03/02/2026" → "2026-03-02". Anything else is left as
     * it is so the row checker can report it.
     */
    private static function date(string $value): string
    {
        $value = trim($value);

        if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $value) === 1) {
            $date = CarbonImmutable::createFromFormat('!m/d/Y', $value);

            return $date !== null ? $date->format('Y-m-d') : $value;
        }

        return $value;
    }

    private static function optionType(string $value): string
    {
        return match (strtoupper(trim($value))) {
            'P', 'PUT' => 'put',
            'C', 'CALL' => 'call',
            default => strtolower(trim($value)),
        };
    }
}
