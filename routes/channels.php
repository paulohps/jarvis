<?php

use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * The private channel of a user (notification bell, US-5.1/US-8.2): only that
 * user may listen.
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return $user->id === $id;
});

/*
 * The private channel of a shopping list (US-8.2): the members of the list's
 * workspace with the `shopping` module may listen, while the list is not
 * deleted.
 */
Broadcast::channel('shopping-lists.{listId}', function (User $user, string $listId): bool {
    return ShoppingList::query()->visibleTo($user)->where('friendly_id', $listId)->exists();
});
