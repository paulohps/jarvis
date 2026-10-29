<?php

namespace App\Http\Resources\Admin;

use App\Models\Module;
use App\Support\Navigation\AppNavigation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A module the super admin can switch on for members, with its name in the
 * reader's language.
 *
 * @mixin Module
 */
class ModuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Module $module */
        $module = $this->resource;

        return [
            'slug' => $module->slug,
            'name' => AppNavigation::moduleLabel($module),
            'is_active' => $module->is_active,
            'is_available_on_mobile' => $module->is_available_on_mobile,
        ];
    }
}
