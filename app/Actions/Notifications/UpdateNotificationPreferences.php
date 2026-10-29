<?php

namespace App\Actions\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\NotificationGate;
use App\Services\Notifications\NotificationPreferenceMatrix;
use App\Services\Notifications\NotificationRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The user switches notification types on or off per channel (US-3.3),
 * copied from my-finance. Only the cells of their profile matrix are
 * accepted (`NotificationPreferenceMatrix`): a type hidden from the profile,
 * a channel switched off or the WhatsApp channel without an account and a
 * number answer 422.
 *
 * Each cell sent is stored as an explicit choice, and the cells not sent
 * keep theirs (or the default). The gate's memo is dropped, so the next
 * dispatch already obeys.
 */
final readonly class UpdateNotificationPreferences
{
    public function __construct(
        private NotificationPreferenceMatrix $matrix,
        private NotificationRegistry $registry,
        private NotificationGate $gate,
    ) {}

    /**
     * @param  array{preferences?: mixed}  $input  `preferences`: type key => channel slug => enabled
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, array $input): void
    {
        /** @var array{preferences: array<string, array<string, bool>>} $validated */
        $validated = Validator::make($input, [
            'preferences' => ['required', 'array'],
            'preferences.*' => ['required', 'array'],
            'preferences.*.*' => ['required', 'boolean'],
        ])->validate();

        $cells = $this->matrix->cellsFor($user);
        $errors = [];

        foreach ($validated['preferences'] as $key => $channels) {
            foreach (array_keys($channels) as $channel) {
                $field = "preferences.{$key}.{$channel}";

                if (! NotificationPreferenceMatrix::isChannelAvailableFor($user, $channel)) {
                    $errors[$field] = __('Choose the WhatsApp account and number for notifications first.');
                } elseif (! in_array($channel, $cells[$key] ?? [], true)) {
                    $errors[$field] = __('This notification cannot be changed on this channel.');
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($user, $validated): void {
            foreach ($validated['preferences'] as $key => $channels) {
                $typeId = $this->registry->settingsFor($key)?->id;

                foreach ($channels as $channel => $isEnabled) {
                    NotificationPreference::query()->updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'notification_type_id' => $typeId,
                            'notification_channel_id' => NotificationChannel::idFor($channel),
                        ],
                        ['is_enabled' => (bool) $isEnabled],
                    );
                }
            }
        });

        $this->gate->forget($user);
    }
}
