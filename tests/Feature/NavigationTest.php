<?php

use App\Models\Account;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::factory()->create();
});

it('opens every page in the menu and the quick actions', function () {
    $html = $this->get(route('dashboard'))->assertOk()->getContent();

    preg_match('/<nav aria-label="Main".*?<\/nav>/s', (string) $html, $menu);
    preg_match_all('/href="([^"]+)"/', $menu[0] ?? '', $links);

    expect($links[1])->toHaveCount(21);

    foreach ([...$links[1], route('stock-trades.create'), route('options.create'), route('options.buy')] as $url) {
        $this->get(html_entity_decode($url))->assertOk();
    }
});

it('highlights the page being viewed', function (string $url, string $label) {
    preg_match_all('/aria-current="page".*?>\s*(.*?)\s*<\/a>/s', (string) $this->get($url)->assertOk()->getContent(), $active);

    expect($active[1])->toBe([$label]);
})->with([
    'closed options' => fn () => [route('options.index', ['status' => 'closed']), 'Closed options'],
    'buy option' => fn () => [route('options.buy'), 'Buy option'],
    'cash ledger' => fn () => [route('cash.index'), 'Cash ledger'],
]);
