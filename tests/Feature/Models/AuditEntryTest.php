<?php

use App\Models\Account;
use App\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('does not allow an audit entry to be changed through the model', function () {
    $entry = Account::factory()->create()->auditEntries()->sole();

    $entry->update(['reason' => 'Rewriting history']);
})->throws(LogicException::class, 'Audit entries cannot be changed.');

it('does not allow an audit entry to be deleted through the model', function () {
    $entry = Account::factory()->create()->auditEntries()->sole();

    $entry->delete();
})->throws(LogicException::class, 'Audit entries cannot be deleted.');

it('blocks changes made directly in the database', function () {
    Account::factory()->create();

    DB::table('audit_entries')->update(['reason' => 'Rewriting history']);
})->throws(QueryException::class, 'Audit entries cannot be changed.');

it('blocks deletes made directly in the database', function () {
    Account::factory()->create();

    DB::table('audit_entries')->delete();
})->throws(QueryException::class, 'Audit entries cannot be deleted.');

it('does not record an entry when a save changes nothing', function () {
    $account = Account::factory()->create();

    $account->withAuditReason('No real change')->update(['nickname' => $account->nickname]);

    expect(AuditEntry::count())->toBe(1);
});

it('records only the fields that changed and leaves out timestamps', function () {
    $account = Account::factory()->create(['nickname' => 'Before']);

    $account->update(['nickname' => 'After']);

    $entry = $account->auditEntries()->latest('id')->first();

    expect($entry->old_values)->toBe(['nickname' => 'Before'])
        ->and($entry->new_values)->toBe(['nickname' => 'After'])
        ->and($entry->auditable_type)->toBe('account');
});
