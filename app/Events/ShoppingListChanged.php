<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A shopping list or one of its items changed (US-8.2): everyone with the
 * list open reloads it without a refresh. Broadcast on the list's private
 * channel (`shopping-lists.{friendly_id}`, see routes/channels.php), open to
 * the members of its workspace with the `shopping` module, with what changed — the payload is
 * a hint; the screens read the list again.
 *
 * Dispatched after the transaction commits, so a listener never reloads a
 * state that is not saved yet.
 */
final class ShoppingListChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public const string LIST_CREATED = 'list.created';

    public const string LIST_RENAMED = 'list.renamed';

    public const string LIST_ARCHIVED = 'list.archived';

    public const string LIST_UNARCHIVED = 'list.unarchived';

    public const string LIST_DELETED = 'list.deleted';

    public const string LIST_DEFAULT_CHANGED = 'list.default_changed';

    public const string ITEM_ADDED = 'item.added';

    public const string ITEM_UPDATED = 'item.updated';

    public const string ITEM_REMOVED = 'item.removed';

    public const string ITEM_TOGGLED = 'item.toggled';

    public const string ITEMS_REORDERED = 'items.reordered';

    public const string CHECKED_CLEARED = 'checked.cleared';

    public function __construct(
        public readonly string $listId,
        public readonly string $change,
        public readonly ?string $itemId = null,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelFor($this->listId))];
    }

    public function broadcastAs(): string
    {
        return 'shopping-list.changed';
    }

    /**
     * @return array{list_id: string, change: string, item_id: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'list_id' => $this->listId,
            'change' => $this->change,
            'item_id' => $this->itemId,
        ];
    }

    /**
     * The private channel of a list, without the `private-` prefix.
     */
    public static function channelFor(string $listId): string
    {
        return 'shopping-lists.'.$listId;
    }
}
