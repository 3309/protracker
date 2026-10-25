<?php

use App\Models\Setting;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Settings')] class extends Component
{
    /**
     * Time zones offered for displaying times. Trade dates are calendar dates
     * and are never shifted by this setting.
     */
    public const array TIMEZONES = [
        'America/New_York' => 'US Eastern (market time)',
        'America/Chicago' => 'US Central',
        'America/Denver' => 'US Mountain',
        'America/Phoenix' => 'US Arizona',
        'America/Los_Angeles' => 'US Pacific',
        'America/Anchorage' => 'US Alaska',
        'Pacific/Honolulu' => 'US Hawaii',
        'UTC' => 'UTC',
    ];

    public string $reporting_start_date = '';

    public string $stale_price_threshold_days = '';

    public string $display_timezone = '';

    public string $reason = '';

    public function mount(): void
    {
        $settings = Setting::current();

        $this->reporting_start_date = $settings->reporting_start_date->format('Y-m-d');
        $this->stale_price_threshold_days = (string) $settings->stale_price_threshold_days;
        $this->display_timezone = $settings->display_timezone;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'reporting_start_date' => ['required', 'date_format:Y-m-d'],
            'stale_price_threshold_days' => ['required', 'integer', 'between:1,90'],
            'display_timezone' => ['required', Rule::in(array_keys(self::TIMEZONES))],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'reporting_start_date' => 'reporting start date',
            'stale_price_threshold_days' => 'stale price warning',
            'display_timezone' => 'display time zone',
        ]);

        $settings = Setting::current();

        if ($validated['reporting_start_date'] !== $settings->reporting_start_date->format('Y-m-d') && $this->reportingStartDateIsLocked) {
            $this->addError('reporting_start_date', 'The reporting start date cannot change because opening balances or trades have been entered.');

            return;
        }

        $settings
            ->withAuditReason($validated['reason'] ?: null)
            ->update([
                'reporting_start_date' => $validated['reporting_start_date'],
                'stale_price_threshold_days' => (int) $validated['stale_price_threshold_days'],
                'display_timezone' => $validated['display_timezone'],
            ]);

        $this->reset('reason');

        session()->now('message', 'Settings saved.');
    }

    #[Computed]
    public function reportingStartDateIsLocked(): bool
    {
        return Setting::current()->reportingStartDateIsLocked();
    }
};
?>

<div class="flex flex-col gap-6">
    <h1 class="page-title">Settings</h1>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    <form wire:submit="save" class="card flex max-w-xl flex-col gap-4">
        <div>
            <label for="reporting_start_date" class="form-label required">Reporting start date</label>
            <input id="reporting_start_date" type="date" wire:model="reporting_start_date" class="form-input" @disabled($this->reportingStartDateIsLocked)>
            @if ($this->reportingStartDateIsLocked)
                <p class="form-help">Locked because opening balances or trades have been entered. Changing it would make them wrong.</p>
            @else
                <p class="form-help">Reports and totals start on this date. Positions held before it are entered as opening balances.</p>
            @endif
            @error('reporting_start_date') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="stale_price_threshold_days" class="form-label required">Warn about stale prices after (days)</label>
            <input id="stale_price_threshold_days" type="number" min="1" max="90" wire:model="stale_price_threshold_days" class="form-input">
            <p class="form-help">A manually entered price older than this is flagged as stale.</p>
            @error('stale_price_threshold_days') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="display_timezone" class="form-label required">Display time zone</label>
            <select id="display_timezone" wire:model="display_timezone" class="form-input">
                @foreach ($this::TIMEZONES as $timezone => $label)
                    <option value="{{ $timezone }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('display_timezone') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="reason" class="form-label">Reason for change (optional)</label>
            <input id="reason" type="text" wire:model="reason" class="form-input">
            @error('reason') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save settings</button>
        </div>
    </form>
</div>
