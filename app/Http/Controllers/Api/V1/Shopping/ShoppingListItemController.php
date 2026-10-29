<?php

namespace App\Http\Controllers\Api\V1\Shopping;

use App\Actions\Shopping\AddShoppingListItem;
use App\Actions\Shopping\RemoveShoppingListItem;
use App\Actions\Shopping\ToggleShoppingListItem;
use App\Actions\Shopping\UpdateShoppingListItem;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShoppingListItemResource;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The items of a shopping list in the apps (US-8.1, US-8.2), through the
 * same Actions as the web screen: an item of a list of another workspace is
 * not found (404).
 */
class ShoppingListItemController extends Controller
{
    /**
     * `POST /api/v1/shopping/lists/{list}/items`: `name` and `quantity_note`.
     */
    public function store(Request $request, ShoppingList $list, AddShoppingListItem $addItem): JsonResponse
    {
        $item = $addItem($this->actor($request), $list, $request->only(['name', 'quantity_note']));

        return $this->itemResource($item)->response()->setStatusCode(201);
    }

    /**
     * `PATCH /api/v1/shopping/items/{item}`: `name` and `quantity_note`.
     */
    public function update(Request $request, ShoppingListItem $item, UpdateShoppingListItem $updateItem): ShoppingListItemResource
    {
        return $this->itemResource($updateItem($this->actor($request), $item, $request->only(['name', 'quantity_note'])));
    }

    /**
     * `DELETE /api/v1/shopping/items/{item}`.
     */
    public function destroy(Request $request, ShoppingListItem $item, RemoveShoppingListItem $removeItem): Response
    {
        $removeItem($this->actor($request), $item);

        return response()->noContent();
    }

    /**
     * `POST /api/v1/shopping/items/{item}/toggle`: `checked` is the state the
     * item should end in — so replaying a mark made offline gives the same
     * result — and `client_updated_at`, the moment the mark was made on the
     * device, decides conflicts by last write. The item comes back as it is
     * saved, which is not the requested state when a later write won.
     */
    public function toggle(Request $request, ShoppingListItem $item, ToggleShoppingListItem $toggleItem): ShoppingListItemResource
    {
        $validated = $request->validate([
            'checked' => ['required', 'boolean'],
            'client_updated_at' => ['nullable', 'date'],
        ]);

        $clientUpdatedAt = filled($validated['client_updated_at'] ?? null)
            ? CarbonImmutable::parse($validated['client_updated_at'])
            : null;

        return $this->itemResource($toggleItem($this->actor($request), $item, (bool) $validated['checked'], $clientUpdatedAt));
    }

    private function itemResource(ShoppingListItem $item): ShoppingListItemResource
    {
        return new ShoppingListItemResource($item->load(['list' => fn ($query) => $query->withTrashed(), 'checkedBy']));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
