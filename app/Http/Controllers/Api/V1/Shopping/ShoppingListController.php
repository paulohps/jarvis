<?php

namespace App\Http\Controllers\Api\V1\Shopping;

use App\Actions\Shopping\ArchiveShoppingList;
use App\Actions\Shopping\ClearCheckedItems;
use App\Actions\Shopping\CompleteShoppingList;
use App\Actions\Shopping\CreateShoppingList;
use App\Actions\Shopping\DeleteShoppingList;
use App\Actions\Shopping\RenameShoppingList;
use App\Actions\Shopping\ReorderShoppingListItems;
use App\Actions\Shopping\SetDefaultShoppingList;
use App\Actions\Shopping\UnarchiveShoppingList;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShoppingListResource;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The shopping lists in the apps (US-8.1, US-8.2), through the same Actions
 * as the web screens — so the same validation and the same
 * `ShoppingListChanged` broadcasts. A user sees the lists of every
 * workspace where they have the `shopping` module, each with its
 * `workspace`; a list of another workspace is not found (404).
 */
class ShoppingListController extends Controller
{
    public const string FILTER_ACTIVE = 'active';

    public const string FILTER_ARCHIVED = 'archived';

    /**
     * `GET /api/v1/shopping/lists?filter=active|archived`: the active lists
     * by name, or the archived ones the latest archived first, as on the web.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filter = $request->validate([
            'filter' => ['nullable', 'string', Rule::in([self::FILTER_ACTIVE, self::FILTER_ARCHIVED])],
        ])['filter'] ?? self::FILTER_ACTIVE;

        $query = ShoppingList::query()->visibleTo($this->actor($request))->withItemCounts()->with(['schedule', 'workspace']);

        $lists = $filter === self::FILTER_ARCHIVED
            ? $query->archived()->latest('archived_at')->latest('id')->get()
            : $query->notArchived()->orderBy('name')->orderBy('id')->get();

        return ShoppingListResource::collection($lists);
    }

    /**
     * `POST /api/v1/shopping/lists`: `name` and, optionally, `workspace` (a
     * friendly id; the user's default when left out).
     */
    public function store(Request $request, CreateShoppingList $createList): JsonResponse
    {
        $list = $createList($this->actor($request), $request->only(['name', 'workspace']));

        return $this->listResource($list)->response()->setStatusCode(201);
    }

    /**
     * `GET /api/v1/shopping/lists/{list}`: the list with its items, unchecked
     * ones first and checked ones at the end.
     */
    public function show(Request $request, ShoppingList $list): ShoppingListResource
    {
        if (! $list->isVisibleTo($this->actor($request))) {
            throw (new ModelNotFoundException)->setModel(ShoppingList::class, [$list->getKey()]);
        }

        return $this->listResource($list);
    }

    /**
     * `PATCH /api/v1/shopping/lists/{list}`: renames it (`name`) and/or moves
     * it to another of the user's workspaces (`workspace`).
     */
    public function update(Request $request, ShoppingList $list, RenameShoppingList $renameList): ShoppingListResource
    {
        return $this->listResource($renameList($this->actor($request), $list, $request->only(['name', 'workspace'])));
    }

    /**
     * `DELETE /api/v1/shopping/lists/{list}`.
     */
    public function destroy(Request $request, ShoppingList $list, DeleteShoppingList $deleteList): Response
    {
        $deleteList($this->actor($request), $list);

        return response()->noContent();
    }

    /**
     * `POST /api/v1/shopping/lists/{list}/archive`.
     */
    public function archive(Request $request, ShoppingList $list, ArchiveShoppingList $archiveList): ShoppingListResource
    {
        return $this->listResource($archiveList($this->actor($request), $list));
    }

    /**
     * `POST /api/v1/shopping/lists/{list}/complete`: archived as done; a
     * recurring list gets its next one at once.
     */
    public function complete(Request $request, ShoppingList $list, CompleteShoppingList $completeList): ShoppingListResource
    {
        return $this->listResource($completeList($this->actor($request), $list));
    }

    /**
     * `POST /api/v1/shopping/lists/{list}/unarchive`.
     */
    public function unarchive(Request $request, ShoppingList $list, UnarchiveShoppingList $unarchiveList): ShoppingListResource
    {
        return $this->listResource($unarchiveList($this->actor($request), $list));
    }

    /**
     * `POST /api/v1/shopping/lists/{list}/default`: makes it its workspace's
     * default, the list a voice command that names none adds to.
     */
    public function makeDefault(Request $request, ShoppingList $list, SetDefaultShoppingList $setDefault): ShoppingListResource
    {
        return $this->listResource($setDefault($this->actor($request), $list));
    }

    /**
     * `DELETE /api/v1/shopping/lists/{list}/default`: it stops being the default.
     */
    public function unsetDefault(Request $request, ShoppingList $list, SetDefaultShoppingList $setDefault): ShoppingListResource
    {
        return $this->listResource($setDefault($this->actor($request), $list, false));
    }

    /**
     * `POST /api/v1/shopping/lists/{list}/clear-checked`: the list as it is
     * left, with `meta.removed`.
     */
    public function clearChecked(Request $request, ShoppingList $list, ClearCheckedItems $clearChecked): ShoppingListResource
    {
        $removed = $clearChecked($this->actor($request), $list);

        return $this->listResource($list)->additional(['meta' => ['removed' => $removed]]);
    }

    /**
     * `POST /api/v1/shopping/lists/{list}/reorder` with `items`, the item ids
     * in the new order: the list in that order.
     */
    public function reorder(Request $request, ShoppingList $list, ReorderShoppingListItems $reorderItems): ShoppingListResource
    {
        return $this->listResource($reorderItems($this->actor($request), $list, $request->input('items')));
    }

    private function listResource(ShoppingList $list): ShoppingListResource
    {
        $list->load(['items.checkedBy', 'workspace'])->loadCount([
            'items',
            'items as unchecked_items_count' => fn ($query) => $query->whereNull('checked_at'),
        ]);

        return new ShoppingListResource($list);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
