<?php

namespace App\Enums;

/**
 * A standalone cash movement entered by hand. Deposits and withdrawals are
 * never income; interest and other items appear in the cash ledger but not
 * in realized profit.
 */
enum CashMovementType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Interest = 'interest';
    case OtherCredit = 'other_credit';
    case OtherCharge = 'other_charge';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::Interest => 'Interest',
            self::OtherCredit => 'Other credit',
            self::OtherCharge => 'Other charge',
        };
    }

    /**
     * Whether this kind of movement adds cash to the account.
     */
    public function isCredit(): bool
    {
        return match ($this) {
            self::Deposit, self::Interest, self::OtherCredit => true,
            self::Withdrawal, self::OtherCharge => false,
        };
    }
}
