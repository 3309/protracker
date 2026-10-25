<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\Setting;
use App\Support\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A base for report pages, with account and reporting-period filters. The
 * choices are kept in the page address so a filtered view can be bookmarked.
 *
 * The period is a calendar year (from the reporting start year to this
 * year) or a custom range of dates.
 */
abstract class ReportPage extends Component
{
    /** An account ID, or blank for all accounts. */
    #[Url]
    public string $account = '';

    /** A year such as "2026", or "custom" to use the from/to dates. */
    #[Url]
    public string $year = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /**
     * Accounts offered in the account filter, archived ones included so
     * their history can still be reported.
     *
     * @return Collection<int, Account>
     */
    #[Computed]
    public function filterAccounts(): Collection
    {
        return Account::query()->orderByRaw('archived_at is not null')->orderBy('nickname')->get();
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function availableYears(): array
    {
        $firstYear = Setting::current()->reporting_start_date->year;

        return range(max($firstYear, $this->today()->year), $firstYear);
    }

    /**
     * The selected period, or null when a custom range is incomplete or invalid.
     */
    #[Computed]
    public function reportingPeriod(): ?ReportingPeriod
    {
        if ($this->year === 'custom') {
            try {
                return ReportingPeriod::between($this->from, $this->to);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        $availableYears = $this->availableYears();
        $year = in_array((int) $this->year, $availableYears, true) ? (int) $this->year : $availableYears[0];

        return ReportingPeriod::forYear($year);
    }

    #[Computed]
    public function displayTimezone(): string
    {
        return Setting::current()->display_timezone;
    }

    /**
     * Today's calendar date as seen in the display time zone, as a plain date
     * (midnight in the app time zone) so it compares correctly with trade,
     * expiration and other calendar dates.
     */
    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($this->displayTimezone())->format('Y-m-d'));
    }

    /**
     * Limit a query to the selected account, if one is selected.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applyAccountFilter(Builder $query, string $column = 'account_id'): Builder
    {
        if ($this->account !== '') {
            $query->where($column, (int) $this->account);
        }

        return $query;
    }
}
