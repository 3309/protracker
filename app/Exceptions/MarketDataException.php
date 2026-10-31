<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when stock quotes cannot be fetched at all: no provider is set up,
 * the provider could not be reached, or its answer was unusable. The
 * message is safe to show on the page and never contains the API key;
 * no price has been saved or changed.
 */
class MarketDataException extends Exception
{
    public static function notConfigured(): self
    {
        return new self('Automatic stock prices are not set up yet. Add MARKET_DATA_PROVIDER=tiingo and your MARKET_DATA_API_KEY to the .env file, then try again. You can still enter prices by hand.');
    }

    public static function keyRejected(): self
    {
        return new self('The market-data provider did not accept the API key. Check MARKET_DATA_API_KEY in the .env file.');
    }

    public static function rateLimited(): self
    {
        return new self('The market-data provider says the request limit for your plan has been reached. Wait a while (the free Tiingo plan allows 50 requests an hour) and try again.');
    }

    public static function unreachable(): self
    {
        return new self('The market-data provider could not be reached or took too long to answer. Check your internet connection and try again.');
    }

    public static function unavailable(): self
    {
        return new self('The market-data provider had a problem and returned no prices. Try again in a few minutes.');
    }

    public static function unreadable(): self
    {
        return new self('The market-data provider sent an answer that could not be understood, so no prices were changed. Try again in a few minutes.');
    }

    public static function alreadyRunning(): self
    {
        return new self('Stock prices are already being refreshed. Wait a moment for that to finish.');
    }

    public static function tooSoon(int $seconds): self
    {
        return new self("Stock prices were refreshed less than {$seconds} seconds ago. Wait a moment before refreshing again, so the provider's request limit is not used up.");
    }
}
