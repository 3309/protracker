<?php

namespace App\Enums;

/**
 * The kind of a line in the cash ledger.
 */
enum CashCategory: string
{
    case OpeningBalance = 'opening_balance';
    case StockBuy = 'stock_buy';
    case StockSale = 'stock_sale';
    case OptionPremium = 'option_premium';
    case OptionBuyBack = 'option_buy_back';
    case Dividend = 'dividend';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Interest = 'interest';
    case OtherCredit = 'other_credit';
    case OtherCharge = 'other_charge';

    public static function fromMovementType(CashMovementType $type): self
    {
        return match ($type) {
            CashMovementType::Deposit => self::Deposit,
            CashMovementType::Withdrawal => self::Withdrawal,
            CashMovementType::Interest => self::Interest,
            CashMovementType::OtherCredit => self::OtherCredit,
            CashMovementType::OtherCharge => self::OtherCharge,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::StockBuy => 'Stock buy',
            self::StockSale => 'Stock sale',
            self::OptionPremium => 'Option premium',
            self::OptionBuyBack => 'Option buy-back',
            self::Dividend => 'Dividend',
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::Interest => 'Interest',
            self::OtherCredit => 'Other credit',
            self::OtherCharge => 'Other charge',
        };
    }
}
