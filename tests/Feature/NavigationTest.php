<?php

use App\Models\Account;
use App\Models\OptionPosition;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Account::factory()->create();
});

it('opens every page in the menu and the quick actions', function () {
    $html = $this->get(route('dashboard'))->assertOk()->getContent();

    preg_match('/<nav aria-label="Main".*?<\/nav>/s', (string) $html, $menu);
    preg_match_all('/href="([^"]+)"/', $menu[0] ?? '', $links);

    expect($links[1])->toHaveCount(22);

    foreach ([...$links[1], route('stock-trades.create'), route('options.create'), route('options.buy')] as $url) {
        $this->get(html_entity_decode($url))->assertOk();
    }
});

it('highlights the page being viewed', function (string $url, string $label) {
    preg_match_all('/aria-current="page".*?>(.*?)<\/a>/s', (string) $this->get($url)->assertOk()->getContent(), $active);

    expect(array_map(fn (string $link) => trim(strip_tags($link)), $active[1]))->toBe([$label]);
})->with([
    'closed options' => fn () => [route('options.index', ['status' => 'closed']), 'Closed options'],
    'buy option' => fn () => [route('options.buy'), 'Buy option'],
    'cash ledger' => fn () => [route('cash.index'), 'Cash ledger'],
]);

it('keeps the menu group of the page being viewed open', function (string $url, string $group) {
    preg_match_all('/data-nav-section="([^"]+)"\s+data-current/', (string) $this->get($url)->assertOk()->getContent(), $current);

    expect($current[1])->toBe([$group]);
})->with([
    'option detail page' => fn () => [route('options.show', OptionPosition::factory()->create()), 'options'],
    'opening balances for an account' => fn () => [route('opening-balances.show', Account::first()), 'settings'],
    'dashboard' => fn () => [route('dashboard'), 'overview'],
]);

it('keeps sidebar choices separate for each user', function () {
    $this->get(route('dashboard'))->assertOk()->assertSee("'sidebar:{$this->user->id}:'", false);
});
