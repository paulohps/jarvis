<?php

namespace App\Services\Chats;

use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\User;
use App\Services\Slack\SlackChatProvider;
use App\Services\Whatsapp\WhatsappChatProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The drivers of the chat layer, one per ChatProvider slug (Key Concept
 * "Chat channel"). A provider in the lookup without a driver here can be
 * switched on by the super admin but shows nothing on the chat screen.
 */
final class ChatProviderRegistry
{
    /**
     * @var array<string, class-string<ChatProviderDriver>>
     */
    private const array DRIVERS = [
        ChatProvider::WHATSAPP => WhatsappChatProvider::class,
        ChatProvider::SLACK => SlackChatProvider::class,
    ];

    /**
     * @var array<string, ChatProviderDriver>
     */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function has(string $slug): bool
    {
        return isset(self::DRIVERS[$slug]);
    }

    /**
     * @throws InvalidArgumentException for a slug without a driver
     */
    public function driver(string $slug): ChatProviderDriver
    {
        if (! $this->has($slug)) {
            throw new InvalidArgumentException("No chat driver for provider [{$slug}].");
        }

        return $this->resolved[$slug] ??= $this->container->make(self::DRIVERS[$slug]);
    }

    public function for(ChatConversation $conversation): ChatProviderDriver
    {
        return $this->driver($conversation->provider->slug);
    }

    /**
     * The active providers with a driver, in menu order.
     *
     * @return EloquentCollection<int, ChatProvider>
     */
    public function providers(): EloquentCollection
    {
        return ChatProvider::query()
            ->active()
            ->whereIn('slug', array_keys(self::DRIVERS))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The providers the user may use on the chat screen, in menu order.
     *
     * @return EloquentCollection<int, ChatProvider>
     */
    public function providersFor(User $user): EloquentCollection
    {
        return $this->providers()
            ->filter(fn (ChatProvider $provider): bool => $user->hasChatProvider($provider->slug))
            ->values();
    }

    /**
     * The drivers the user may use, by slug.
     *
     * @return Collection<string, ChatProviderDriver>
     */
    public function driversFor(User $user): Collection
    {
        return $this->providersFor($user)
            ->mapWithKeys(fn (ChatProvider $provider): array => [$provider->slug => $this->driver($provider->slug)])
            ->toBase();
    }
}
