/**
 * Whether the desktop sidebar of the signed-in area is collapsed to its icons
 * (`$store.sidebar`). The choice is the browser's, kept in localStorage, and
 * shows as `data-sidebar="collapsed"` on <html> — set before the first paint
 * by the inline script of <x-ui.document>, so the page does not jump — which
 * the CSS reads through the `sidebar-collapsed:` variant.
 */
const STORAGE_KEY = 'jarvis.sidebar';

function apply(collapsed) {
    if (collapsed) {
        document.documentElement.dataset.sidebar = 'collapsed';
    } else {
        delete document.documentElement.dataset.sidebar;
    }
}

document.addEventListener('alpine:init', () => {
    let saved = null;

    try {
        saved = window.localStorage.getItem(STORAGE_KEY);
    } catch {
        // Storage blocked: the sidebar starts expanded.
    }

    window.Alpine.store('sidebar', {
        collapsed: saved === 'collapsed',

        init() {
            apply(this.collapsed);
        },

        toggle() {
            this.collapsed = !this.collapsed;
            apply(this.collapsed);

            try {
                window.localStorage.setItem(STORAGE_KEY, this.collapsed ? 'collapsed' : 'expanded');
            } catch {
                // Storage blocked: the choice lasts until the page reloads.
            }
        },
    });
});

// A wire:navigate visit may swap the document's attributes: keep the choice.
document.addEventListener('livewire:navigated', () => apply(window.Alpine?.store('sidebar')?.collapsed ?? false));
