<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when data cannot be fetched from the local Alpaca service: it is not
 * set up, could not be reached, refused the request, or its answer was
 * unusable. The message is safe to show on the page and is fixed text, so it
 * never contains the API token or anything the service sent back.
 */
class AlpacaServiceException extends Exception
{
    public static function notConfigured(): self
    {
        return new self('The Alpaca service is not set up yet. Add ALPACA_SERVICE_TOKEN (the API_TOKEN from the Node.js service) to the .env file, then try again.');
    }

    public static function notLocal(): self
    {
        return new self('ALPACA_SERVICE_URL must point to this computer, such as http://127.0.0.1:3001, so the API token is never sent over a network. No request was made.');
    }

    public static function unreachable(): self
    {
        return new self('The Alpaca service could not be reached or took too long to answer. Check that the Node.js service is running and that ALPACA_SERVICE_URL has the right port.');
    }

    public static function tokenRejected(): self
    {
        return new self('The Alpaca service did not accept the API token. Check that ALPACA_SERVICE_TOKEN matches API_TOKEN in the Node.js service.');
    }

    public static function notPaper(): self
    {
        return new self('The Alpaca service did not confirm that it is read-only and using a paper account, so no account data is shown.');
    }

    public static function rateLimited(?int $retryAfterSeconds): self
    {
        $wait = $retryAfterSeconds !== null ? "Wait {$retryAfterSeconds} seconds" : 'Wait a moment';

        return new self("The request limit has been reached. {$wait} and refresh.");
    }

    public static function noData(): self
    {
        return new self('Alpaca has no data for this request. Check the symbol and any filters.');
    }

    public static function dataRestricted(): self
    {
        return new self('Your Alpaca market data plan does not include this data.');
    }

    public static function alpacaKeysRejected(): self
    {
        return new self('The Alpaca service is running, but Alpaca rejected its keys. Check the paper keys in the Node.js service.');
    }

    public static function alpacaPermissionDenied(): self
    {
        return new self('The Alpaca service is running, but Alpaca denied access to this data for this account.');
    }

    public static function alpacaUnreachable(): self
    {
        return new self('The Alpaca service is running, but it could not reach Alpaca. Try again in a few minutes.');
    }

    public static function alpacaError(): self
    {
        return new self('The Alpaca service is running, but Alpaca could not complete the request. Try again in a few minutes.');
    }

    public static function invalidRequest(): self
    {
        return new self('The symbol or filters are not valid. No data was fetched.');
    }

    public static function unavailable(): self
    {
        return new self('The Alpaca service had a problem and returned no data. Try again in a few minutes.');
    }

    public static function malformed(): self
    {
        return new self('The Alpaca service sent an answer that could not be understood, so nothing is shown. Check that ALPACA_SERVICE_URL points to the right service.');
    }
}
