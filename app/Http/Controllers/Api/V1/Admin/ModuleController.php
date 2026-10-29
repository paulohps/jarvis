<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ModuleResource;
use App\Models\Module;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The modules the super admin can switch on for members, in menu order.
 */
class ModuleController extends Controller
{
    /**
     * `GET /api/v1/admin/modules`.
     */
    public function index(): AnonymousResourceCollection
    {
        return ModuleResource::collection(Module::query()->orderBy('sort_order')->orderBy('id')->get());
    }
}
