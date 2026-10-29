@props(['title' => null])

{{--
    A full-window screen without the panel (no sidebar, tab bar or top
    bar), for the WhatsApp screen opened in a tab of its own. Installed as
    an app, the screen starts below the status bar.
--}}
<x-ui.document :title="$title" class="h-dvh overflow-hidden pt-[env(safe-area-inset-top)]">
    {{ $slot }}
</x-ui.document>
