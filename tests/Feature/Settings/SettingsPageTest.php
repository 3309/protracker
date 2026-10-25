<?php

use App\Enums\AuditEvent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('starts with the agreed default settings', function () {
    $settings = Setting::current();

    expect($settings->reporting_start_date->format('Y-m-d'))->toBe('2026-01-01')
        ->and($settings->stale_price_threshold_days)->toBe(3)
        ->and($settings->display_timezone)->toBe('America/New_York');
});

it('keeps a single settings row', function () {
    Setting::current();
    Setting::current();

    expect(Setting::count())->toBe(1);
});

it('shows the current settings on the page', function () {
    Livewire::test('pages::settings.general')
        ->assertSet('reporting_start_date', '2026-01-01')
        ->assertSet('stale_price_threshold_days', '3')
        ->assertSet('display_timezone', 'America/New_York');
});

it('saves changed settings and records them in the audit history', function () {
    Livewire::test('pages::settings.general')
        ->set('reporting_start_date', '2027-01-01')
        ->set('stale_price_threshold_days', '5')
        ->set('display_timezone', 'America/Chicago')
        ->set('reason', 'Starting a new year')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Settings saved.');

    $settings = Setting::current();

    expect($settings->reporting_start_date->format('Y-m-d'))->toBe('2027-01-01')
        ->and($settings->stale_price_threshold_days)->toBe(5)
        ->and($settings->display_timezone)->toBe('America/Chicago')
        ->and($settings->auditEntries()->latest('id')->first())
        ->event->toBe(AuditEvent::Updated)
        ->reason->toBe('Starting a new year');
});

it('stores the reporting start date as a plain calendar date', function () {
    Livewire::test('pages::settings.general')
        ->set('reporting_start_date', '2027-01-01')
        ->call('save');

    expect(DB::table('settings')->value('reporting_start_date'))->toBe('2027-01-01');
});

it('rejects invalid settings', function (string $field, string $value, string $rule) {
    Livewire::test('pages::settings.general')
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field => $rule]);
})->with([
    'zero-day stale threshold' => ['stale_price_threshold_days', '0', 'between'],
    'unknown time zone' => ['display_timezone', 'Mars/Olympus', 'in'],
    'badly formatted date' => ['reporting_start_date', '01/01/2026', 'date_format'],
]);
