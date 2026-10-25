{{-- Light, dark or system theme; see partials.theme-script. Plain JavaScript, so it also works on pages without Livewire. --}}
<div>
    <label for="theme" class="sr-only">Theme</label>
    <select
        id="theme"
        onchange="window.chooseTheme(this.value)"
        class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200"
    >
        <option value="system">Theme: system</option>
        <option value="light">Theme: light</option>
        <option value="dark">Theme: dark</option>
    </select>
    <script>document.getElementById('theme').value = window.chosenTheme();</script>
</div>
