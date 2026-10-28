<?php

namespace App\Support\TradeAssistant;

use App\Exceptions\TradeAssistantException;

/**
 * Turns a plain-English trade description into a proposed transaction.
 *
 * Implementations talk to one AI provider and nothing else: they never save
 * anything. The proposal they return is checked again by the entry forms
 * before it can be saved.
 */
interface TradeExtractor
{
    /**
     * @throws TradeAssistantException when the provider is not set up, cannot be reached, or answers with something unusable
     */
    public function extract(string $description, TradeContext $context): TradeProposal;
}
