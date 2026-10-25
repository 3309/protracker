<?php

namespace App\Enums;

/**
 * The kinds of row an import understands. Anything else is flagged as not
 * recognized rather than guessed.
 */
enum ImportAction: string
{
    case Buy = 'buy';
    case Sell = 'sell';
    case SellToOpen = 'sell_to_open';
    case BuyToClose = 'buy_to_close';
    case Expired = 'expired';
    case Assigned = 'assigned';
    case Dividend = 'dividend';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Interest = 'interest';
    case OtherCredit = 'other_credit';
    case OtherCharge = 'other_charge';

    public function label(): string
    {
        return match ($this) {
            self::Buy => 'Stock buy',
            self::Sell => 'Stock sell',
            self::SellToOpen => 'Sell to open',
            self::BuyToClose => 'Buy to close',
            self::Expired => 'Option expired',
            self::Assigned => 'Option assigned',
            self::Dividend => 'Dividend',
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::Interest => 'Interest',
            self::OtherCredit => 'Other credit',
            self::OtherCharge => 'Other charge',
        };
    }

    public function isStockTrade(): bool
    {
        return $this === self::Buy || $this === self::Sell;
    }

    public function isOptionContract(): bool
    {
        return in_array($this, [self::SellToOpen, self::BuyToClose, self::Expired, self::Assigned], true);
    }

    /**
     * Acts on an option position that must already be open.
     */
    public function closesOption(): bool
    {
        return in_array($this, [self::BuyToClose, self::Expired, self::Assigned], true);
    }

    public function isCashMovement(): bool
    {
        return $this->cashMovementType() !== null;
    }

    public function cashMovementType(): ?CashMovementType
    {
        return match ($this) {
            self::Deposit => CashMovementType::Deposit,
            self::Withdrawal => CashMovementType::Withdrawal,
            self::Interest => CashMovementType::Interest,
            self::OtherCredit => CashMovementType::OtherCredit,
            self::OtherCharge => CashMovementType::OtherCharge,
            default => null,
        };
    }

    /**
     * The order rows are imported in on the same date, so that positions are
     * opened before they are closed and shares are bought before they are sold.
     */
    public function importOrder(): int
    {
        return match ($this) {
            self::Deposit, self::OtherCredit, self::Interest => 0,
            self::Buy => 1,
            self::SellToOpen => 2,
            self::Dividend => 3,
            self::BuyToClose => 4,
            self::Expired => 5,
            self::Assigned => 6,
            self::Sell => 7,
            self::Withdrawal, self::OtherCharge => 8,
        };
    }
}
