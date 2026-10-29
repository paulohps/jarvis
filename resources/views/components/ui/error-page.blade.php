<x-layouts::guest :title="$title">
    <x-ui.auth-card :title="$title" :description="$message">
        <div class="flex flex-col items-center gap-5">
            <p aria-hidden="true" class="text-6xl font-bold tracking-tight text-tint tabular-nums">{{ $code }}</p>

            <x-button :href="route('dashboard')" :text="__('Back to the home page')" icon="arrow-left" />
        </div>
    </x-ui.auth-card>
</x-layouts::guest>
