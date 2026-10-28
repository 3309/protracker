<?php

namespace App\Support\TradeAssistant;

use App\Exceptions\TradeAssistantException;

/**
 * Used while no AI provider or API key is set up: every request explains
 * how to set it up, and nothing is guessed.
 */
class UnconfiguredTradeExtractor implements TradeExtractor
{
    public function extract(string $description, TradeContext $context): TradeProposal
    {
        throw TradeAssistantException::notConfigured();
    }
}
