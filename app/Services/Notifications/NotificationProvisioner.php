<?php

namespace App\Services\Notifications;

use App\Models\Locale;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Support\Localization;
use Illuminate\Support\Facades\DB;

/**
 * Writes the rows a registered notification type needs before it can be
 * sent (US-5.4, US-5.5): the type, active and visible in the profile, one
 * row per channel the type declares, and its templates in every interface
 * language (`en` and `pt_BR`).
 *
 * It runs on every deploy and on `db:seed` (`notifications:provision`), so a
 * type ships with the code. It is idempotent: an existing type or channel row
 * keeps its switches, a missing template is created, and a template nobody
 * edited follows the type's current defaults. A template the super admin
 * edited (`updated_by_id`) is never touched.
 */
final readonly class NotificationProvisioner
{
    public function __construct(private NotificationRegistry $registry) {}

    /**
     * Provisions every registered type.
     *
     * @return list<string> the keys of the types whose rows changed
     */
    public function provisionAll(): array
    {
        $provisioned = [];

        foreach ($this->registry->classes() as $class) {
            if ($this->provision($class)) {
                $provisioned[] = $class::key();
            }
        }

        return $provisioned;
    }

    /**
     * Creates the missing rows of one type and refreshes its unedited
     * templates.
     *
     * @param  class-string<CenterNotification>  $class
     * @return bool whether anything was written
     */
    public function provision(string $class): bool
    {
        $changed = DB::transaction(function () use ($class): bool {
            $type = NotificationType::query()->firstOrCreate(
                ['slug' => $class::key()],
                ['is_active' => true, 'is_visible_in_profile' => true],
            );

            $changed = $type->wasRecentlyCreated;

            foreach ($class::channels() as $slug) {
                $typeChannel = NotificationTypeChannel::query()->firstOrCreate(
                    [
                        'notification_type_id' => $type->id,
                        'notification_channel_id' => NotificationChannel::idFor($slug),
                    ],
                    [
                        'is_active' => true,
                        'is_enabled_by_default' => $class::defaults($slug, Locale::EN)->isEnabledByDefault,
                    ],
                );

                $changed = $typeChannel->wasRecentlyCreated || $changed;

                foreach (Localization::SUPPORTED_LOCALES as $locale) {
                    $changed = $this->writeTemplate($typeChannel, $locale, $class::defaults($slug, $locale)) || $changed;
                }
            }

            return $changed;
        });

        $this->registry->flush();

        return $changed;
    }

    private function writeTemplate(NotificationTypeChannel $typeChannel, string $locale, ChannelDefaults $defaults): bool
    {
        $template = NotificationTemplate::query()->firstOrNew([
            'notification_type_channel_id' => $typeChannel->id,
            'locale_id' => Locale::idFor($locale),
        ]);

        if ($template->exists && $template->updated_by_id !== null) {
            return false;
        }

        $template->fill([
            'title' => $defaults->title,
            'body' => $defaults->body,
            'actions' => $defaults->actions,
        ]);

        if (! $template->isDirty()) {
            return false;
        }

        $template->save();

        return true;
    }
}
