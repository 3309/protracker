{{--
    One button for the theme: each click moves light → dark → system → light; see partials.theme-script. It shows the
    current mode's icon. Plain JavaScript, so it also works on pages without Livewire.
--}}
<button
    type="button"
    data-theme-button
    onclick="window.cycleTheme()"
    aria-label="Theme"
    class="flex rounded-md p-2 text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white"
>
    <span data-theme-icon="light"><x-icon name="sun" /></span>
    <span data-theme-icon="dark" hidden><x-icon name="moon" /></span>
    <span data-theme-icon="system" hidden><x-icon name="computer" /></span>
</button>
<script>window.syncThemeButtons();</script>
