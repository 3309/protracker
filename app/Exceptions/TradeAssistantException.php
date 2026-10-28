<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when the AI Trade Assistant cannot read a description: it is not
 * set up, the AI service could not be reached, or its answer was unusable.
 * The message is safe to show on the page; nothing has been saved.
 */
class TradeAssistantException extends Exception
{
    public static function notConfigured(): self
    {
        return new self('The AI Trade Assistant is not set up yet. Add AI_PROVIDER=anthropic and your AI_API_KEY to the .env file, then try again. You can still enter trades with the normal forms.');
    }

    public static function keyRejected(): self
    {
        return new self('The AI service did not accept the API key. Check AI_API_KEY in the .env file and that the API account has billing set up.');
    }

    public static function outOfCredit(): self
    {
        return new self('Your Anthropic API account is out of credit. Add credit at console.anthropic.com under Settings → Billing, wait a minute, then try again. A Claude.ai subscription does not include API use.');
    }

    public static function busy(): self
    {
        return new self('The AI service is busy or your API account has reached its usage limit. Wait a minute and try again.');
    }

    public static function unreachable(): self
    {
        return new self('The AI service could not be reached or took too long to answer. Check your internet connection and try again.');
    }

    public static function unavailable(): self
    {
        return new self('The AI service had a problem and could not read the description. Try again in a moment.');
    }

    public static function unreadable(): self
    {
        return new self('The AI service gave an answer that could not be understood, so nothing was filled in. Try again, or describe the trade a little differently.');
    }

    public static function declined(): self
    {
        return new self('The AI service declined to read this description. Try describing the trade differently, or use the normal entry forms.');
    }
}
