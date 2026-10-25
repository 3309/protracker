{{-- Applies the chosen theme (light, dark, or system to follow the computer's setting) before the page is drawn, so it never flashes. The choice is kept in this browser only. --}}
<script>
    (() => {
        const query = window.matchMedia('(prefers-color-scheme: dark)');

        window.chosenTheme = () => {
            try {
                return localStorage.getItem('theme') ?? 'system';
            } catch (error) {
                return 'system';
            }
        };

        window.applyTheme = () => {
            const theme = window.chosenTheme();

            document.documentElement.classList.toggle('dark', theme === 'dark' || (theme === 'system' && query.matches));
        };

        window.chooseTheme = (theme) => {
            try {
                localStorage.setItem('theme', theme);
            } catch (error) {
                // Storage can be blocked; the theme still applies until the page is left.
            }

            window.applyTheme();
        };

        window.applyTheme();
        query.addEventListener('change', window.applyTheme);
        document.addEventListener('livewire:navigated', window.applyTheme);
    })();
</script>
