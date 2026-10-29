<?php

namespace App\Support\Navigation;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;

/**
 * An entry of the app navigation (sidebar, tab bar and dashboard cards): a
 * translated label, the named route it opens and its Heroicon.
 *
 * `isActive()` also matches the routes below it (`shopping.index` is active
 * on `shopping.show`). A module whose screens are not built yet has no route:
 * its entry is listed without a link until the route is registered.
 *
 * A group gathers related entries (`children`): it opens its first one that
 * exists, is active on any of them or on the routes of `activeRoutes`, and
 * the sidebar lists its entries under it while the tab bar shows only it.
 */
final readonly class NavigationItem
{
    /**
     * @param  list<NavigationItem>  $children
     * @param  list<string>  $activeRoutes  route name patterns that also make it active
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $route,
        public string $icon,
        public array $children = [],
        public array $activeRoutes = [],
    ) {}

    public function isGroup(): bool
    {
        return $this->children !== [];
    }

    public function exists(): bool
    {
        if ($this->isGroup()) {
            return $this->firstChild() !== null;
        }

        return Route::has($this->route);
    }

    /**
     * The URL of the entry, or null while its route is not registered.
     */
    public function url(): ?string
    {
        if ($this->isGroup()) {
            return $this->firstChild()?->url();
        }

        return $this->exists() ? route($this->route) : null;
    }

    public function isActive(): bool
    {
        if ($this->isGroup()) {
            return collect($this->children)->contains(fn (NavigationItem $child): bool => $child->isActive())
                || ($this->activeRoutes !== [] && Request::routeIs(...$this->activeRoutes));
        }

        if (! $this->exists()) {
            return false;
        }

        $prefix = preg_replace('/\.index\z/', '', $this->route);

        return Request::routeIs($this->route, "{$prefix}.*", ...$this->activeRoutes);
    }

    /**
     * The entry of a group shown now, if any.
     */
    public function activeChild(): ?NavigationItem
    {
        return collect($this->children)->first(fn (NavigationItem $child): bool => $child->isActive());
    }

    private function firstChild(): ?NavigationItem
    {
        return collect($this->children)->first(fn (NavigationItem $child): bool => $child->exists());
    }
}
