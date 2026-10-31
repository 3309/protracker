{{--
    Sidebar state: the narrow icon-only rail on wide screens, which menu groups are closed, the labels shown beside
    rail icons, and the slide-in menu on phones and tablets. Choices are kept per user in this browser only.
    One button in the top bar does both jobs (window.sidebar.toggle). Livewire resets the <html> attributes on every
    page change, so the layout calls window.sidebar.restore() straight after the sidebar to put them back before the
    page is drawn.
--}}
<script>
    (() => {
        const prefix = @js('sidebar:'.auth()->id().':');
        const wide = window.matchMedia('(min-width: 64rem)');
        let tooltip = null;

        const read = (key, fallback) => {
            try {
                return JSON.parse(localStorage.getItem(prefix + key)) ?? fallback;
            } catch (error) {
                return fallback;
            }
        };

        const write = (key, value) => {
            try {
                localStorage.setItem(prefix + key, JSON.stringify(value));
            } catch (error) {
                // Storage can be blocked; the choice still applies until the page is left.
            }
        };

        const isCollapsed = () => read('collapsed', false) === true;

        const closedSections = () => {
            const keys = read('closedSections', []);

            return Array.isArray(keys) ? keys : [];
        };

        const setSectionOpen = (section, open) => {
            section.querySelector('[aria-expanded]').setAttribute('aria-expanded', open ? 'true' : 'false');
            section.querySelector('.nav-panel').dataset.open = open ? 'true' : 'false';
        };

        const hideTooltip = () => {
            if (tooltip) {
                tooltip.hidden = true;
            }
        };

        const showTooltip = (target) => {
            if (! wide.matches || ! document.documentElement.classList.contains('sidebar-collapsed')) {
                return hideTooltip();
            }

            if (! tooltip?.isConnected) {
                tooltip = document.createElement('div');
                tooltip.className = 'nav-tooltip';
                tooltip.setAttribute('aria-hidden', 'true');
                document.body.append(tooltip);
            }

            const box = target.getBoundingClientRect();

            tooltip.textContent = target.dataset.tooltip;
            tooltip.style.top = `${box.top + box.height / 2}px`;
            tooltip.style.left = `${box.right + 8}px`;
            tooltip.hidden = false;
        };

        window.sidebar = {
            restore() {
                const collapsed = isCollapsed();
                const closed = closedSections();

                document.documentElement.classList.toggle('sidebar-collapsed', collapsed);

                this.syncToggle();

                document.querySelectorAll('[data-nav-section]').forEach((section) => {
                    setSectionOpen(section, section.hasAttribute('data-current') || ! closed.includes(section.dataset.navSection));
                });
            },

            /** The top bar's menu button: shrinks or widens the sidebar on wide screens, opens or closes the menu on small ones. */
            toggle() {
                if (wide.matches) {
                    write('collapsed', ! isCollapsed());
                    hideTooltip();
                    this.restore();
                } else if (document.getElementById('sidebar')?.hasAttribute('data-open')) {
                    this.closeMenu();
                } else {
                    this.openMenu();
                }
            },

            /** Keeps the menu button's label and state in step with what a click would do. */
            syncToggle() {
                const open = wide.matches
                    ? ! isCollapsed()
                    : document.getElementById('sidebar')?.hasAttribute('data-open') === true;
                const label = wide.matches
                    ? (open ? 'Collapse sidebar' : 'Expand sidebar')
                    : (open ? 'Close menu' : 'Open menu');

                document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
                    button.setAttribute('aria-expanded', open ? 'true' : 'false');
                    button.setAttribute('aria-label', label);
                    button.title = label;
                });
            },

            toggleSection(button) {
                const section = button.closest('[data-nav-section]');
                const open = button.getAttribute('aria-expanded') !== 'true';
                const closed = closedSections().filter((key) => key !== section.dataset.navSection);

                write('closedSections', open ? closed : [...closed, section.dataset.navSection]);
                setSectionOpen(section, open);
            },

            openMenu() {
                document.getElementById('sidebar').dataset.open = '';
                document.body.classList.add('overflow-hidden');
                document.querySelector('#sidebar a')?.focus();
                this.syncToggle();
            },

            closeMenu() {
                const sidebar = document.getElementById('sidebar');

                if (sidebar?.hasAttribute('data-open')) {
                    delete sidebar.dataset.open;
                    document.body.classList.remove('overflow-hidden');
                    document.querySelector('[data-sidebar-toggle]')?.focus();
                    this.syncToggle();
                }
            },
        };

        document.addEventListener('mouseover', (event) => {
            const target = event.target.closest?.('#sidebar [data-tooltip]');

            target ? showTooltip(target) : hideTooltip();
        });
        document.addEventListener('focusin', (event) => {
            const target = event.target.closest?.('#sidebar [data-tooltip]');

            target ? showTooltip(target) : hideTooltip();
        });
        document.addEventListener('focusout', hideTooltip);
        document.addEventListener('scroll', hideTooltip, true);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideTooltip();
                window.sidebar.closeMenu();
            }
        });
        document.addEventListener('livewire:navigating', hideTooltip);
        wide.addEventListener('change', () => {
            hideTooltip();
            window.sidebar.closeMenu();
            window.sidebar.syncToggle();
        });

        document.documentElement.classList.toggle('sidebar-collapsed', isCollapsed());
    })();
</script>
