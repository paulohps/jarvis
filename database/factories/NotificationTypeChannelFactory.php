<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<NotificationTypeChannel>
 */
class NotificationTypeChannelFactory extends Factory
{
    use ResolvesLookups;

    /**
     * The types tried, in order, when no type is given.
     *
     * @var list<string>
     */
    private const array TYPE_SLUGS = [
        NotificationType::INVOICE_SENT, NotificationType::INVOICE_FAILED, NotificationType::INVOICE_PENDING_REVIEW,
        NotificationType::WHATSAPP_ACCOUNT_DISCONNECTED, NotificationType::WHATSAPP_MESSAGE_FAILED,
        NotificationType::REMINDER_DUE, NotificationType::INVITATION, NotificationType::MODULE_ENABLED,
    ];

    /**
     * @var list<string>
     */
    private const array CHANNEL_SLUGS = [
        NotificationChannel::DATABASE, NotificationChannel::MAIL, NotificationChannel::PUSH, NotificationChannel::WHATSAPP,
    ];

    /**
     * Pairs handed out by this factory and not necessarily saved yet:
     * `count(n)->create()` makes every model before inserting any.
     *
     * @var array<int, list<int>>
     */
    private array $claimedChannelIdsByType = [];

    /**
     * An active type × channel pair not configured yet, so several can be
     * created without breaking the `(type, channel)` unique.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_type_id' => fn (): int => $this->firstTypeIdWithAFreeChannel(),
            'notification_channel_id' => fn (array $attributes): int => $this->claimChannelIdFor($attributes['notification_type_id']),
            'is_active' => true,
            'is_enabled_by_default' => true,
        ];
    }

    private function firstTypeIdWithAFreeChannel(): int
    {
        foreach (self::TYPE_SLUGS as $typeSlug) {
            $typeId = static::lookupId(NotificationType::class, $typeSlug, ['is_active' => true]);

            if ($this->freeChannelIdFor($typeId) !== null) {
                return $typeId;
            }
        }

        throw new RuntimeException('Every notification type × channel pair is configured.');
    }

    private function claimChannelIdFor(int $typeId): int
    {
        $channelId = $this->freeChannelIdFor($typeId)
            ?? throw new RuntimeException("Every channel of the notification type [{$typeId}] is configured.");

        $this->claimedChannelIdsByType[$typeId][] = $channelId;

        return $channelId;
    }

    private function freeChannelIdFor(int $typeId): ?int
    {
        $usedChannelIds = NotificationTypeChannel::query()
            ->where('notification_type_id', $typeId)
            ->pluck('notification_channel_id')
            ->merge($this->claimedChannelIdsByType[$typeId] ?? []);

        foreach (self::CHANNEL_SLUGS as $channelSlug) {
            $channelId = static::lookupId(NotificationChannel::class, $channelSlug);

            if (! $usedChannelIds->contains($channelId)) {
                return $channelId;
            }
        }

        return null;
    }
}
