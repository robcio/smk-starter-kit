// Progressive disclosure: server HTML always exposes links and hides inactive controls.
export function initNavigation(root = document) {
    const desktop = matchMedia('(min-width: 64rem)');
    const controllers = [];
    root.querySelectorAll('[data-smk-disclosure]').forEach(owner => {
        if (owner.dataset.smkReady) return;
        const button = [...owner.querySelectorAll('[data-smk-toggle]')]
            .find(control => control.closest('[data-smk-disclosure]') === owner);
        const panel = button && document.getElementById(button.getAttribute('aria-controls'));
        if (!button || !panel || !owner.contains(panel)) return;
        const mobile = owner.dataset.smkDisclosure === 'mobile';
        const setOpen = (open, restore = false) => {
            if (!open && panel.contains(document.activeElement)) button.focus();
            panel.hidden = !open;
            button.setAttribute('aria-expanded', String(open));
            if (restore) button.focus();
        };
        button.addEventListener('click', () => setOpen(button.getAttribute('aria-expanded') !== 'true'));
        owner.addEventListener('keydown', event => {
            if (event.key !== 'Escape' || panel.hidden || (mobile && desktop.matches)) return;
            event.preventDefault();
            event.stopPropagation();
            setOpen(false, true);
        });
        const sync = () => {
            if (!mobile) return;
            // Preserve the focused content when crossing the breakpoint.
            const hasFocus = panel.contains(document.activeElement);
            if (desktop.matches) {
                setOpen(true);
                if (document.activeElement === button) panel.querySelector('a,button:not([hidden])')?.focus();
                button.hidden = true;
            } else {
                button.hidden = false;
                setOpen(hasFocus);
            }
        };
        button.hidden = false;
        setOpen(false);
        owner.dataset.smkReady = 'true';
        sync();
        controllers.push(sync);
    });
    desktop.addEventListener('change', () => controllers.forEach(sync => sync()));
    const header = root.querySelector('[data-smk-header][data-smk-sticky]');
    if (header && !header.dataset.smkStickyReady && typeof ResizeObserver !== 'undefined') {
        const measure = () => {
            const height = header.getBoundingClientRect().height;
            const active = desktop.matches && height < innerHeight / 3;
            header.toggleAttribute('data-smk-sticky-active', active);
            document.documentElement.style.setProperty('--smk-header-height', active ? `${height}px` : '0px');
        };
        new ResizeObserver(measure).observe(header);
        addEventListener('resize', measure);
        desktop.addEventListener('change', measure);
        header.dataset.smkStickyReady = 'true';
        measure();
    }
}
