<?php

use App\Models\Account;

it('stores fees as whole cents in the database', function () {
    $account = Account::factory()->create();

    expect(DB::table('accounts')->where('id', $account->id)->value('option_fee_per_contract'))->toBe(67);
});

it('refuses to store a fee given as a plain number instead of a Money value', function (mixed $fee) {
    Account::factory()->create(['option_fee_per_contract' => $fee]);
})->throws(InvalidArgumentException::class, 'must be set with a Money value')->with([
    'float' => 0.67,
    'integer' => 67,
    'string' => '0.67',
]);
