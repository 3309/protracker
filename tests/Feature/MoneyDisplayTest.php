<?php

use App\Support\Money;

it('shows P/L as green gains, red losses and grey zero, with a sign and arrow besides the color', function (string $amount, string $text, string $color, ?string $arrow) {
    $view = $this->blade('<x-money :amount="$amount" />', ['amount' => Money::fromDecimal($amount)]);

    $view->assertSee($text)->assertSee($color, false);

    $arrow === null ? $view->assertDontSee('aria-hidden', false) : $view->assertSee($arrow);
})->with([
    'gain' => ['1250', '+$1,250.00', 'text-green-700', '▲'],
    'loss' => ['-425.50', '-$425.50', 'text-red-700', '▼'],
    'zero' => ['0', '$0.00', 'text-zinc-500', null],
]);

it('shows balances without a plus sign, negative ones in red', function (string $amount, string $text, ?string $color) {
    $view = $this->blade('<x-money :amount="$amount" tone="balance" />', ['amount' => Money::fromDecimal($amount)])
        ->assertSee($text)
        ->assertDontSee('+$')
        ->assertDontSee('text-green-700', false);

    $color === null ? $view->assertDontSee('text-red-700', false) : $view->assertSee($color, false);
})->with([
    'positive' => ['1234.56', '$1,234.56', null],
    'negative' => ['-1234.56', '-$1,234.56', 'text-red-700'],
    'zero' => ['0', '$0.00', 'text-zinc-500'],
]);

it('shows a dash when there is no amount', function () {
    $this->blade('<x-money :amount="null" />')->assertSee('—');
});
