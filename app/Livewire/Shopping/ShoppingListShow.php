<?php

namespace App\Livewire\Shopping;

use App\Actions\Shopping\AddShoppingListItem;
use App\Actions\Shopping\ArchiveShoppingList;
use App\Actions\Shopping\ClearCheckedItems;
use App\Actions\Shopping\CompleteShoppingList;
use App\Actions\Shopping\DeleteShoppingList;
use App\Actions\Shopping\RemoveShoppingListItem;
use App\Actions\Shopping\RenameShoppingList;
use App\Actions\Shopping\ReorderShoppingListItems;
use App\Actions\Shopping\SetDefaultShoppingList;
use App\Actions\Shopping\ToggleShoppingListItem;
use App\Actions\Shopping\UnarchiveShoppingList;
use App\Actions\Shopping\UpdateShoppingListItem;
use App\Events\ShoppingListChanged;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One shopping list, made for one hand in the market (US-8.1, US-8.2;
 * Workflow 11): the round checkbox of each item, the checked ones at the end
 * with their text struck through, "Clear checked", and the "Add item" field
 * fixed at the bottom, above the tab bar.
 *
 * Every change made elsewhere arrives through Echo on the list's private
 * channel (ShoppingListChanged) and reloads the items, so two people with
 * the list open see each other's marks without a refresh. When the list is
 * deleted elsewhere, the screen goes back to the lists.
 *
 * An archived list is read only: its items show, and it can be unarchived,
 * renamed or deleted.
 *
 * A list of a recurring one shows its deadline and "Complete": it is
 * archived as done and the screen goes to the next list, made at once.
 *
 * Only a member of the list's workspace with the `shopping` module sees it
 * (anyone else gets a 404); one with the module in several workspaces sees
 * its workspace's color and may move it to another in the rename sheet.
 *
 * @property-read ShoppingList $list
 * @property-read Collection<int, ShoppingListItem> $items
 * @property-read array<string, string> $workspaceOptions
 */
#[Layout('layouts::app-shell')]
class ShoppingListShow extends Component
{
    #[Locked]
    public string $listId = '';

    public string $itemName = '';

    public string $itemNote = '';

    public bool $showItemForm = false;

    #[Locked]
    public ?string $editingItemId = null;

    public string $editName = '';

    public string $editNote = '';

    public bool $showRenameForm = false;

    public string $listName = '';

    /**
     * The workspace (friendly id) picked in the rename sheet.
     */
    public string $listWorkspace = '';

    public function mount(ShoppingList $list): void
    {
        if (! $list->isVisibleTo($this->actor())) {
            throw (new ModelNotFoundException)->setModel(ShoppingList::class, [$list->getKey()]);
        }

        $this->listId = $list->friendly_id;
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:'.ShoppingListChanged::channelFor($this->listId).',.shopping-list.changed' => 'refreshList',
        ];
    }

    #[Computed]
    public function list(): ShoppingList
    {
        return $this->visibleLists()->where('friendly_id', $this->listId)->with('workspace')->firstOrFail();
    }

    /**
     * The user's workspaces with the `shopping` module, by name: friendly id
     * => name.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function workspaceOptions(): array
    {
        return $this->actor()->membershipsWithModule(Module::SHOPPING)
            ->mapWithKeys(fn (WorkspaceMembership $membership): array => [$membership->workspace->friendly_id => $membership->workspace->name])
            ->all();
    }

    /**
     * Whether the workspace shows: only for a user with the module in more
     * than one.
     */
    public function showsWorkspaces(): bool
    {
        return count($this->workspaceOptions) > 1;
    }

    /**
     * The items in display order: unchecked ones first, checked ones at the
     * end.
     *
     * @return Collection<int, ShoppingListItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return $this->list->items()->get();
    }

    #[Computed]
    public function checkedCount(): int
    {
        return $this->items->filter(fn (ShoppingListItem $item): bool => $item->isChecked())->count();
    }

    /**
     * Reloads the list after a change made by anyone, or leaves the screen
     * when the list was deleted or moved to a workspace the user does not
     * see.
     */
    public function refreshList(): void
    {
        unset($this->list, $this->items, $this->checkedCount);

        if (! $this->visibleLists()->where('friendly_id', $this->listId)->exists()) {
            $this->skipRender();
            $this->redirectRoute('shopping.index', navigate: true);
        }
    }

    public function addItem(AddShoppingListItem $addItem): void
    {
        $this->resetErrorBag();

        $addItem($this->actor(), $this->list, ['name' => $this->itemName, 'quantity_note' => $this->itemNote]);

        $this->reset('itemName', 'itemNote');
        $this->refreshList();
    }

    public function toggle(string $itemId, ToggleShoppingListItem $toggleItem): void
    {
        $this->resetErrorBag();

        $item = $this->findItem($itemId);

        $toggleItem($this->actor(), $item, ! $item->isChecked());

        $this->refreshList();
    }

    /**
     * An unchecked item dragged to `$position` among the unchecked ones
     * (`wire:sort`).
     */
    public function reorder(string $itemId, int $position, ReorderShoppingListItems $reorderItems): void
    {
        $this->resetErrorBag();

        $order = $this->items
            ->reject(fn (ShoppingListItem $item): bool => $item->isChecked() || $item->friendly_id === $itemId)
            ->pluck('friendly_id')
            ->all();

        array_splice($order, max(0, $position), 0, [$itemId]);

        $reorderItems($this->actor(), $this->list, $order);

        $this->refreshList();
    }

    public function clearChecked(ClearCheckedItems $clearChecked): void
    {
        $this->resetErrorBag();

        $clearChecked($this->actor(), $this->list);

        $this->refreshList();
    }

    public function editItem(string $itemId): void
    {
        $item = $this->findItem($itemId);

        $this->resetValidation();
        $this->editingItemId = $item->friendly_id;
        $this->editName = $item->name;
        $this->editNote = (string) $item->quantity_note;
        $this->showItemForm = true;
    }

    public function updateItem(UpdateShoppingListItem $updateItem): void
    {
        $this->resetErrorBag();

        if ($this->editingItemId === null) {
            return;
        }

        $updateItem($this->actor(), $this->findItem($this->editingItemId), [
            'name' => $this->editName,
            'quantity_note' => $this->editNote,
        ]);

        $this->closeItemForm();
        $this->refreshList();
    }

    public function removeItem(RemoveShoppingListItem $removeItem): void
    {
        $this->resetErrorBag();

        if ($this->editingItemId === null) {
            return;
        }

        $removeItem($this->actor(), $this->findItem($this->editingItemId));

        $this->closeItemForm();
        $this->refreshList();
    }

    public function editList(): void
    {
        $this->resetValidation();
        $this->listName = $this->list->name;
        $this->listWorkspace = $this->list->workspace->friendly_id;
        $this->showRenameForm = true;
    }

    public function renameList(RenameShoppingList $renameList): void
    {
        $this->resetErrorBag();

        try {
            $renameList($this->actor(), $this->list, [
                'name' => $this->listName,
                'workspace' => $this->showsWorkspaces() ? $this->listWorkspace : null,
            ]);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors[match ($field) {
                    'name' => 'listName',
                    'workspace' => 'listWorkspace',
                    default => $field,
                }] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $this->reset('showRenameForm', 'listName', 'listWorkspace');
        $this->refreshList();
    }

    public function archive(ArchiveShoppingList $archiveList): void
    {
        $this->resetErrorBag();

        $archiveList($this->actor(), $this->list);

        $this->redirectRoute('shopping.index', navigate: true);
    }

    /**
     * Done with a recurring list: on to the next one.
     */
    public function complete(CompleteShoppingList $completeList): void
    {
        $this->resetErrorBag();

        $list = $completeList($this->actor(), $this->list);
        $next = $list->schedule?->openList();

        $next === null
            ? $this->redirectRoute('shopping.index', navigate: true)
            : $this->redirectRoute('shopping.show', ['list' => $next], navigate: true);
    }

    /**
     * Makes the list its workspace's default for voice commands, or unsets it.
     */
    public function toggleDefault(SetDefaultShoppingList $setDefault): void
    {
        $this->resetErrorBag();

        $setDefault($this->actor(), $this->list, ! $this->list->is_default);

        $this->refreshList();
    }

    public function unarchive(UnarchiveShoppingList $unarchiveList): void
    {
        $this->resetErrorBag();

        $unarchiveList($this->actor(), $this->list);

        $this->refreshList();
    }

    public function delete(DeleteShoppingList $deleteList): void
    {
        $this->resetErrorBag();

        $deleteList($this->actor(), $this->list);

        $this->redirectRoute('shopping.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.shopping.shopping-list-show')->title($this->list->name);
    }

    /**
     * @return Builder<ShoppingList>
     */
    private function visibleLists(): Builder
    {
        return ShoppingList::query()->visibleTo($this->actor());
    }

    private function closeItemForm(): void
    {
        $this->reset('showItemForm', 'editingItemId', 'editName', 'editNote');
    }

    private function findItem(string $itemId): ShoppingListItem
    {
        return ShoppingListItem::query()
            ->where('shopping_list_id', $this->list->id)
            ->where('friendly_id', $itemId)
            ->firstOrFail();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
