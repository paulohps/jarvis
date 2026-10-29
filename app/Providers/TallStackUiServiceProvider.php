<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use TallStackUi\Facades\TallStackUi;

/**
 * Dresses TallStackUI in the iOS 27 design system (US-12.2) through soft
 * customization: no published views, nothing under vendor/. Every class
 * written here is a token of resources/css/app.css, so the components follow
 * the light and dark themes of the system.
 *
 * Floating layers (modal, slide, dialog, toast, styled select menu) are glass;
 * fields, cards and tables stay opaque. Below 640px the modal is a sheet that
 * rises from the bottom (config `modal.center = sm`, with a grabber).
 *
 * Toggles are iOS switches; their "on" color comes from
 * App\View\Components\TallStackUi\Colors\ToggleColors.
 */
class TallStackUiServiceProvider extends ServiceProvider
{
    private const string OVERLAY = 'fixed inset-0 bg-overlay transform transition-opacity';

    private const string FIELD_WRAPPER = 'focus-within:ring-tint flex rounded-control ring-1 focus-within:ring-2';

    private const string FIELD_BASE = 'w-full rounded-control border-0 bg-transparent py-2 ring-0 placeholder:text-secondary-label focus:outline-hidden focus:ring-transparent sm:text-sm sm:leading-6';

    private const string FIELD_COLOR = 'ring-separator text-label';

    private const string FIELD_BACKGROUND = 'bg-secondary-grouped-background';

    private const string FIELD_DISABLED = 'bg-fill';

    private const string FIELD_SLOT = 'flex select-none items-center whitespace-nowrap text-secondary-label sm:text-sm';

    private const string FLOATING = 'glass-thick glass-edge absolute z-50 rounded-card';

    private const string ROW_HIGHLIGHT = 'hover:bg-fill focus:bg-fill';

    public function boot(): void
    {
        $this->customizeButton();
        $this->customizeFields();
        $this->customizeSelect();
        $this->customizeToggle();
        $this->customizeModal();
        $this->customizeSlide();
        $this->customizeDialog();
        $this->customizeToast();
        $this->customizeDropdown();
        $this->customizeBadge();
        $this->customizeCard();
        $this->customizeTable();
    }

    /**
     * Semibold labels on a 44px touch target with the control radius; a
     * pressed button shrinks slightly, as on iOS.
     */
    private function customizeButton(): void
    {
        TallStackUi::customize()
            ->button()
            ->block('wrapper.class')->append('font-semibold active:scale-[0.98]')
            ->block([
                'wrapper.sizes.md' => 'text-sm px-4 py-2.5 min-h-11',
                'wrapper.sizes.lg' => 'text-base px-5 py-3 min-h-12',
                'border.radius.md' => 'rounded-control',
                'border.radius.lg' => 'rounded-card',
                'border.radius.xl' => 'rounded-sheet',
            ]);
    }

    /**
     * Text fields, text areas, numbers and passwords share the same field:
     * opaque cell background, hairline ring and the tint when focused.
     */
    private function customizeFields(): void
    {
        foreach (['input', 'textarea', 'number'] as $field) {
            TallStackUi::customize()
                ->form($field)
                ->block([
                    'input.wrapper' => self::FIELD_WRAPPER,
                    'input.base' => self::FIELD_BASE,
                    'input.slot' => self::FIELD_SLOT,
                    'input.color.base' => self::FIELD_COLOR,
                    'input.color.background' => self::FIELD_BACKGROUND,
                    'input.color.disabled' => self::FIELD_DISABLED,
                ]);
        }

        TallStackUi::customize()
            ->form('textarea')
            ->block('count.base', 'absolute right-0 mt-1 text-sm text-secondary-label');

        TallStackUi::customize()
            ->form('label')
            ->block('text', 'mb-1 block text-sm font-medium text-label');

        TallStackUi::customize()
            ->form('hint')
            ->block('text')->remove(['text-gray-500', 'dark:text-dark-400'])->append('text-secondary-label');

        TallStackUi::customize()
            ->wrapper('input')
            ->block('wrapper', 'relative rounded-control');

        TallStackUi::customize()
            ->wrapper('radio')
            ->block('label.text', 'cursor-pointer items-center text-sm font-medium text-label');
    }

    private function customizeSelect(): void
    {
        TallStackUi::customize()
            ->select('native')
            ->block([
                'input.wrapper' => self::FIELD_WRAPPER,
                'input.base' => str_replace('bg-transparent ', '', self::FIELD_BASE),
                'input.slot' => self::FIELD_SLOT,
                'input.color.base' => self::FIELD_COLOR,
                'input.color.background' => self::FIELD_BACKGROUND,
                'input.color.disabled' => self::FIELD_DISABLED,
            ]);

        TallStackUi::customize()
            ->select('styled')
            ->block([
                'input.wrapper.base' => 'flex w-full cursor-pointer items-center gap-x-2 rounded-control border-0 bg-secondary-grouped-background py-2 text-sm ring-1 ring-separator disabled:bg-fill disabled:text-secondary-label',
                'input.wrapper.color' => 'focus:ring-tint text-label focus:outline-hidden focus:ring-2',
                'buttons.base' => 'text-secondary-label hover:text-danger',
                'floating.default' => self::FLOATING,
                'box.list.item.wrapper' => 'relative cursor-pointer select-none px-3 py-2 text-label focus:outline-hidden '.self::ROW_HIGHLIGHT,
                'box.list.item.selected' => 'font-semibold text-tint',
                'box.list.item.disabled' => 'cursor-not-allowed! opacity-50',
                'box.list.empty' => 'block w-full pr-2 text-secondary-label',
                'box.list.grouped.wrapper' => 'my-1 ml-2 font-semibold text-secondary-label',
                'items.placeholder.text' => 'truncate leading-6 text-secondary-label',
                'items.single' => 'truncate leading-6 text-label',
                'items.multiple.item' => 'inline-flex h-6 items-center space-x-1 rounded-full bg-fill px-2 text-sm font-medium text-label',
            ]);
    }

    /**
     * The iOS switch: a 51×31 pill with a 27px white knob that slides 20px.
     * The other sizes keep the same proportions.
     */
    private function customizeToggle(): void
    {
        TallStackUi::customize()
            ->form('toggle')
            ->block([
                'input.class' => 'peer absolute inset-y-0 left-0.5 translate-x-0 my-0.5 transform cursor-pointer appearance-none rounded-full border-0 bg-white shadow-soft transition-transform duration-200 ease-out checked:bg-none checked:text-white focus:outline-hidden ring-0 ring-offset-0 focus:ring-0 focus:ring-offset-0 focus-visible:ring-2 focus-visible:ring-tint',
                'input.sizes.xs' => 'h-3 w-3 checked:translate-x-3',
                'input.sizes.sm' => 'h-5 w-5 checked:translate-x-4',
                'input.sizes.md' => 'h-[27px] w-[27px] checked:translate-x-5',
                'input.sizes.lg' => 'h-[30px] w-[30px] checked:translate-x-[22px]',
                'background.class' => 'bg-fill-pressed block cursor-pointer rounded-full transition-colors duration-200 group-focus:ring-0 group-focus:ring-offset-0 peer-focus:ring-0 peer-focus:ring-offset-0',
                'background.sizes.xs' => 'h-4 w-7',
                'background.sizes.sm' => 'h-6 w-10',
                'background.sizes.md' => 'h-[31px] w-[51px]',
                'background.sizes.lg' => 'h-[34px] w-[56px]',
                'error' => 'bg-danger peer-checked:bg-danger',
            ]);
    }

    /**
     * A glass sheet with the large radius; up to 640px it is anchored to the
     * bottom, rounded only on top, with a grabber to drag it down.
     */
    private function customizeModal(): void
    {
        TallStackUi::customize()
            ->modal()
            ->block([
                'wrapper.first' => self::OVERLAY,
                'wrapper.fourth' => 'glass-thick glass-edge text-label relative flex w-full transform flex-col rounded-t-sheet sm:rounded-sheet text-left transition-all',
                'wrapper.fourth-centered-rounded' => 'rounded-sheet',
                'handle.bar' => 'h-1.5 w-9 rounded-full bg-fill-pressed',
                'title.wrapper' => 'flex items-center justify-between border-b border-b-separator px-4 py-3',
                'title.text' => 'text-base whitespace-normal font-semibold text-label',
                'title.close' => 'text-secondary-label h-5 w-5 cursor-pointer',
                'body' => 'grow py-5 text-label px-4',
                'footer.wrapper' => 'rounded-b-sheet border-t border-t-separator p-4 text-label',
                'footer.scrollable' => 'sticky bottom-0 z-10 glass-thick',
            ]);
    }

    private function customizeSlide(): void
    {
        TallStackUi::customize()
            ->slide()
            ->block([
                'wrapper.first' => self::OVERLAY,
                'wrapper.fifth' => 'glass-thick glass-edge text-label flex flex-col py-6',
                'title.text' => 'whitespace-normal font-semibold text-base text-label',
                'title.close' => 'h-5 w-5 cursor-pointer text-secondary-label',
                'body' => 'soft-scrollbar grow overflow-y-auto px-6 py-5 text-label',
                'footer.wrapper' => 'border-t border-t-separator px-4 pt-4',
                'header.divider' => 'border-b border-b-separator pb-4',
            ]);
    }

    /**
     * The alert of TallStackUI interactions (`$this->dialog()` in Livewire).
     */
    private function customizeDialog(): void
    {
        TallStackUi::customize()
            ->dialog()
            ->block([
                'background' => self::OVERLAY,
                'wrapper.third' => 'glass-thick glass-edge relative w-full max-w-sm transform overflow-hidden rounded-sheet p-5 text-left transition-all sm:my-8',
                'text.title' => 'text-base font-semibold leading-6 text-label',
                'text.description.text' => 'text-sm text-secondary-label',
                'buttons.confirm' => 'cursor-pointer group inline-flex w-full min-h-11 items-center justify-center rounded-control px-4 py-2 text-sm font-semibold text-white outline-hidden transition ease-in',
                'buttons.close.icon' => 'h-5 w-5 cursor-pointer text-secondary-label',
            ]);
    }

    /**
     * A floating glass banner, as an iOS notification.
     */
    private function customizeToast(): void
    {
        TallStackUi::customize()
            ->toast()
            ->block([
                'wrapper.third' => 'glass-thick glass-edge pointer-events-auto w-full max-w-sm overflow-hidden rounded-sheet',
                'content.text' => 'text-sm font-semibold text-label',
                'content.description' => 'mt-1 text-sm text-secondary-label',
                'buttons.close.class' => 'inline-flex text-secondary-label focus:outline-hidden focus:ring-0 cursor-pointer',
                'buttons.expand.class' => 'inline-flex text-secondary-label focus:outline-hidden focus:ring-0',
                'progress.wrapper' => 'relative h-1 w-full rounded-full bg-fill',
                'progress.bar' => 'bg-tint absolute h-full w-24 duration-300 ease-linear',
            ]);
    }

    /**
     * A glass menu (user menu, language switch).
     */
    private function customizeDropdown(): void
    {
        TallStackUi::customize()
            ->dropdown()
            ->block([
                'floating.default' => self::FLOATING,
                'slot.wrapper' => 'overflow-hidden rounded-card',
                'action.text' => 'text-sm font-medium text-label',
                'action.icon' => 'h-5 w-5 cursor-pointer text-secondary-label transition',
            ]);

        TallStackUi::customize()
            ->dropdown('items')
            ->block([
                'item.base' => 'text-label flex w-full cursor-pointer items-center whitespace-nowrap transition-colors duration-150 focus:outline-hidden '.self::ROW_HIGHLIGHT,
                'border' => 'border-t border-t-separator',
                'icon.base' => 'text-secondary-label',
            ]);
    }

    /**
     * A capsule label.
     */
    private function customizeBadge(): void
    {
        TallStackUi::customize()
            ->badge()
            ->block('border.radius.md', 'rounded-full');
    }

    /**
     * An opaque cell group with the card radius and a soft shadow.
     */
    private function customizeCard(): void
    {
        TallStackUi::customize()
            ->card()
            ->block([
                'wrapper.second' => 'bg-secondary-grouped-background text-label flex w-full flex-col overflow-hidden shadow-soft',
                'bordered' => 'border border-separator',
                'header.wrapper.border' => 'border-b border-separator',
                'header.text.size' => 'text-base font-semibold',
                'header.text.color' => 'text-label',
                'body' => 'text-label grow px-4 py-5',
                'footer.wrapper' => 'text-label border-t border-t-separator p-4',
                'image.rounded.top' => 'rounded-t-card',
                'image.rounded.bottom' => 'rounded-b-card',
                'loading.bar' => 'h-full w-1/4 bg-tint animate-indeterminate',
                'loading.overlay' => 'absolute inset-0 z-10 cursor-not-allowed rounded-card bg-system-background/50',
                'border.radius.lg' => 'rounded-card',
                'border.radius.xl' => 'rounded-sheet',
            ]);
    }

    /**
     * Rows of an inset grouped list: opaque cells divided by hairlines, the
     * header in small uppercase secondary labels.
     */
    private function customizeTable(): void
    {
        TallStackUi::customize()
            ->table()
            ->block([
                'wrapper' => 'overflow-hidden rounded-card bg-secondary-grouped-background shadow-soft',
                'table.wrapper' => 'relative soft-scrollbar overflow-auto bg-secondary-grouped-background',
                'table.base' => 'min-w-full divide-y divide-separator',
                'table.th' => 'px-4 py-3 text-xs font-semibold uppercase tracking-wide text-secondary-label',
                'table.th-compact' => 'px-4 py-2 text-xs font-semibold uppercase tracking-wide text-secondary-label',
                'table.tbody' => 'divide-y divide-separator bg-secondary-grouped-background',
                'table.td' => 'whitespace-nowrap px-4 py-3 text-sm text-label',
                'table.td-compact' => 'whitespace-nowrap px-4 py-2 text-sm text-label',
                'table.thead.normal' => 'bg-secondary-grouped-background',
                'table.thead.striped' => 'bg-secondary-grouped-background',
                'row.striped' => 'bg-fill',
                'loading.icon' => 'text-tint absolute bottom-0 left-0 right-0 top-0 m-auto grid h-10 w-10 animate-spin place-items-center',
                'loading.indicator' => 'text-tint absolute inset-0 m-auto grid place-items-center',
                'empty' => 'col-span-full whitespace-nowrap px-4 py-4 text-sm text-secondary-label',
                'empty-compact' => 'col-span-full whitespace-nowrap px-4 py-2.5 text-sm text-secondary-label',
                'slots.header' => 'mb-2 text-secondary-label',
                'slots.footer' => 'mt-2 text-secondary-label',
                'expandable.wrapper' => 'bg-fill',
                'expandable.button' => 'text-secondary-label hover:text-label transition-transform duration-200',
            ]);
    }
}
