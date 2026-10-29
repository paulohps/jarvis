---
paths:
  - 'resources/views/**'
  - 'resources/css/**'
  - 'app/View/**'
  - 'app/Providers/TallStackUiServiceProvider.php'
---

# Views, design system and theme

## iOS 27 look, tokens only
The web mimics iOS 26/27 (Liquid Glass, grouped lists, large titles). Every color is a semantic token in `resources/css/app.css` (`bg-system-background`, `text-label`, `text-secondary-label`, `bg-tint-fill text-on-tint`, `border-separator`, `text-danger`/`success`/`warning`, `chat-*`, `calendar-*`). Never use Tailwind palette colors, hex, `rgb()`/`oklch()` in views — `tests/Unit/ThemeTokensTest.php` fails on `bg-blue-500`, `text-white`, `#fff`, etc.

## Adding a color token
Declare it in `@theme` (light) AND in the `prefers-color-scheme: dark` block (the test checks both sets match), keep text tokens ≥ 4.5:1 on every background (checked). Literals are allowed only on `--token:` lines. A color needed outside CSS (theme-color meta, offline page) goes in `app/Support/ThemeColors.php`, which the test compares to the tokens.

## Dark mode follows the system only
`@custom-variant dark (@media (prefers-color-scheme: dark))`: there is no theme toggle, no `.dark` class, no stored preference. Prefer tokens (which flip by themselves) over `dark:` utilities.

## Custom variants
`sidebar-collapsed:` (desktop sidebar collapsed to icons, `data-sidebar` on `<html>`), `standalone:` (installed PWA). Utilities: `glass`, `glass-thick`, `glass-edge`, `glass-floating`, `ambient-backdrop`. Radii: `rounded-control` (12px), `rounded-card` (20px), `rounded-sheet` (28px); shadows `shadow-soft`, `shadow-floating`.

## Design-system components: <x-ui.*>
Reuse `resources/views/components/ui/*` before writing markup: `page-header`, `inset-grouped-list` + `list-row`, `segmented-control`, `empty-state`, `settings-section`, `search-field`, `confirm-dialog`, `status-badge`, `localized-date`, `money`, `recurrence-picker`, `section-tabs`. Class-backed ones live in `app/View/Components/Ui/` and must document `Props:` and `Slots:` in the class PHPDoc (`SharedUiComponentsTest` checks the core ones). Example screen: `livewire/shopping/shopping-list-index.blade.php`.

## Name collisions with TallStackUI
TallStackUI is registered without prefix (`<x-button>`, `<x-input>`, `<x-modal>`, `<x-toggle>`, `<x-select.native>`, `<x-date>`…). Project components therefore live under a namespace (`x-ui.`, `x-pwa.`, `x-whatsapp.`, `x-chats.`). That is why the date display is `<x-ui.localized-date>`, not `<x-date>`.

## TallStackUI is themed by soft customization
`app/Providers/TallStackUiServiceProvider.php` restyles buttons, fields, select, toggle (iOS switch), modal (bottom sheet below 640px), slide, dialog, toast, dropdown, badge, card, table with token classes — no published vendor views. `primary` and `secondary` palettes are mapped to the tint in `app.css`, `gray`/`dark` to Apple's grays. Change the look there, not per call site. `TallStackUiThemeTest` covers it.

## Tailwind sources
`app.css` scans `resources/views`, `resources/js` (classes built in JS), `app/View`, `TallStackUiServiceProvider.php` and TallStackUI's vendor files. Class strings built in other PHP files (Livewire classes, `app/Support`) are NOT scanned — keep class names in Blade or add an `@source`.

## Layouts
`layouts/app-shell.blade.php`: glass sidebar ≥1024px (collapsible, remembered in localStorage), floating glass tab bar below, sticky top bar turning glass on scroll, notification bell, section tabs for grouped screens, PWA banners. `layouts/guest.blade.php`: ambient backdrop, locale switch for guests, legal links. `layouts/chat.blade.php`: full window, no shell. All wrap `<x-ui.document>`, the only `<html>`/`<head>`.

## Navigation is data, not markup
Menu entries come from `App\Support\Navigation\AppNavigation` (modules the user has, in `sort_order`; WhatsApp+Chats merged into one "Chats" group; admin groups for the super admin). Grouped screens get `<x-ui.section-tabs>` automatically. Don't hard-code links in the layout. Tested by `tests/Feature/AppShellNavigationTest.php`.

## Mobile first, safe areas
Must work at 360px without horizontal scroll. Respect `env(safe-area-inset-*)` (top bar, tab bar, chat layout) and leave bottom padding for the floating tab bar (`main` already has it). Touch targets ≥ 44px (`min-h-11` on buttons). Inputs are forced to 16px on `pointer: coarse` so iOS Safari doesn't zoom — don't fight it.

## Accessibility baseline
One `<h1>` per screen (from `page-header`), skip link, `aria-current="page"` on active nav, `role="status"`/`role="alert"` for messages, icons `aria-hidden="true"` with a text label or `sr-only`, `focus-visible:ring-2 focus-visible:ring-tint`. Reduced motion and reduced transparency are honored globally in `app.css`.

## Blade comment header per view
Each view and component starts with a `{{-- --}}` block saying what the screen shows and its props/slots (see `layouts/app-shell.blade.php`). Keep it current when the behavior changes.

## No inline hex in emails/PDF either
Mail and PDF views (`resources/views/mail/**`, `invoices/pdf.blade.php`) are under `resources/views`, so the token test scans them too; the PDF's fixed colors (no dark mode) live as `--invoice-*` variables in `resources/css/invoice-pdf.css`, inlined into `invoices/pdf.blade.php` for Gotenberg — it is not a Vite entry.
