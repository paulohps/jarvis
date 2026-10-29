<?php

test('components render with the design system tokens', function (string $template, array $classes) {
    $view = $this->blade($template);

    foreach ($classes as $class) {
        $view->assertSee($class, false);
    }
})->with([
    'button' => ['<x-button text="Save" />', ['rounded-control', 'bg-primary-500', 'font-semibold']],
    'input' => ['<x-input label="Name" />', ['rounded-control', 'ring-separator', 'bg-secondary-grouped-background', 'placeholder:text-secondary-label', 'text-label']],
    'native select' => ['<x-select.native :options="[1, 2]" />', ['rounded-control', 'ring-separator', 'bg-secondary-grouped-background']],
    'styled select' => ['<x-select.styled :options="[1, 2]" />', ['rounded-control', 'ring-separator', 'glass-thick']],
    'modal' => ['<x-modal title="Edit">Body</x-modal>', ['glass-thick', 'bg-overlay', 'text-label']],
    'slide' => ['<x-slide title="Filters">Body</x-slide>', ['glass-thick', 'bg-overlay']],
    'toast' => ['<x-toast />', ['glass-thick', 'rounded-sheet', 'text-secondary-label']],
    'badge' => ['<x-badge text="New" />', ['rounded-full']],
    'card' => ['<x-card header="Title">Body</x-card>', ['bg-secondary-grouped-background', 'rounded-card', 'shadow-soft', 'border-separator']],
    'table' => ['<x-table :headers="[[\'index\' => \'name\', \'label\' => \'Name\']]" :rows="[[\'name\' => \'Ana\']]" />', ['rounded-card', 'divide-separator', 'text-secondary-label']],
]);

test('toggles are shaped as ios switches', function () {
    $this->blade('<x-toggle label="Notify me" />')
        ->assertSee('h-[31px] w-[51px]', false)
        ->assertSee('h-[27px] w-[27px] checked:translate-x-5', false)
        ->assertSee('peer-checked:bg-switch-on', false)
        ->assertSee('rounded-full', false);
});

test('the modal opens as a bottom sheet below 640 pixels', function () {
    $this->blade('<x-modal title="Edit">Body</x-modal>')
        ->assertSee('items-end sm:items-center', false)
        ->assertSee('rounded-t-sheet sm:rounded-sheet', false)
        ->assertSee('sm:hidden flex justify-center', false);
});
