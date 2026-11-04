{{-- Where a section's Alpaca data came from and when it was fetched. Expects $result, $source and $timezone. --}}
<p class="text-xs text-zinc-500 dark:text-zinc-400">
    Source: {{ $source }}
    @if ($result->fetchedAt)
        · Last updated: {{ $result->fetchedAt->timezone($timezone)->format('M j, Y g:i:s A T') }}
    @endif
    @if ($result->fromCache)
        · Saved copy, use Refresh for the latest
    @endif
</p>
