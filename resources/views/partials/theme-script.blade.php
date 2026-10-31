{{--
    Applies the chosen theme (light, dark, or system to follow the computer's setting) before the page is drawn, so it
    never flashes. The choice is kept in this browser only. The theme button (partials.theme-switcher) steps through
    the modes in order: light, dark, system, then back to light.
--}}
<script>
    (() => {
        const query = window.matchMedia('(prefers-color-scheme: dark)');
        const modes = ['light', 'dark', 'system'];
        const names = { light: 'Light', dark: 'Dark', system: 'System' };

        window.chosenTheme = () => {
            try {
                const theme = localStorage.getItem('theme');

                return modes.includes(theme) ? theme : 'system';
            } catch (error) {
                return 'system';
            }
        };

        window.applyTheme = () => {
            const theme = window.chosenTheme();

            document.documentElement.classList.toggle('dark', theme === 'dark' || (theme === 'system' && query.matches));
        };

        /** Shows the current mode's icon on every theme button, and says which mode the next click picks. */
        window.syncThemeButtons = () => {
            const theme = window.chosenTheme();
            const next = modes[(modes.indexOf(theme) + 1) % modes.length];
            const label = `Theme: ${names[theme]}. Switch to ${names[next]}`;

            document.querySelectorAll('[data-theme-button]').forEach((button) => {
                button.setAttribute('aria-label', label);
                button.title = label;
                button.querySelectorAll('[data-theme-icon]').forEach((icon) => {
                    icon.hidden = icon.dataset.themeIcon !== theme;
                });
            });
        };

        window.chooseTheme = (theme) => {
            try {
                localStorage.setItem('theme', theme);
            } catch (error) {
                // Storage can be blocked; the theme still applies until the page is left.
            }

            window.applyTheme();
            window.syncThemeButtons();
        };

        window.cycleTheme = () => {
            window.chooseTheme(modes[(modes.indexOf(window.chosenTheme()) + 1) % modes.length]);
        };

        window.applyTheme();
        query.addEventListener('change', window.applyTheme);
        document.addEventListener('livewire:navigated', () => {
            window.applyTheme();
            window.syncThemeButtons();
        });
    })();
</script>
