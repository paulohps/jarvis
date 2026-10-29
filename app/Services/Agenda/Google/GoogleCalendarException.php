<?php

namespace App\Services\Agenda\Google;

use RuntimeException;

/**
 * Google could not be read: unreachable, or refusing the account's
 * connection. The message is for the user.
 */
final class GoogleCalendarException extends RuntimeException {}
