<?php

namespace App\Support;

/**
 * Regular expressions for the number and ticker formats people type into forms.
 */
final class InputFormat
{
    /** A ticker such as AAPL or BRK.B. */
    public const string TICKER = '/^[A-Za-z][A-Za-z0-9.\-]{0,9}$/';

    /** A per-share price with up to 4 decimal places, e.g. 50, 1.20 or 0.2425. */
    public const string PRICE = '/^\$?\d{1,7}(\.\d{1,4})?$/';

    /** A fee in dollars with up to 2 decimal places, e.g. 0.67. */
    public const string FEE = '/^\$?\d{1,6}(\.\d{1,2})?$/';

    /** A number of shares with up to 6 decimal places, e.g. 100 or 0.5. */
    public const string SHARES = '/^\d{1,9}(\.\d{1,6})?$/';

    /** A dollar amount that may be negative, e.g. 2000, -150.25 or $2000.00. */
    public const string SIGNED_MONEY = '/^-?\$?\d{1,10}(\.\d{1,2})?$/';
}
