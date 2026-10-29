<?php

namespace App\Models;

/**
 * What a recurring shopping list starts with when the next one is made:
 * the items left unchecked in the previous list, all of its items
 * (unchecked again), the schedule's template plus the ones left unchecked,
 * or nothing.
 */
enum ShoppingListNextContent: string
{
    case Pending = 'pending';
    case All = 'all';
    case TemplateAndPending = 'template_and_pending';
    case Empty = 'empty';
}
