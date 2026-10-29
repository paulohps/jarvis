<?php

namespace App\Services\Templates\Nodes;

/**
 * A node of a parsed template.
 *
 * The tree is deliberately tiny: text, variable output, `if` and `for`. There
 * are no expressions, no filters and no function calls, so nothing here can
 * execute anything.
 */
interface Node {}
