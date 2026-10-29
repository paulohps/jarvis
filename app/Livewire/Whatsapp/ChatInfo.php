<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Whatsapp\StarWhatsappChatMessage;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The side panel of an open chat of the WhatsApp screen, as on WhatsApp
 * Web: the contact's or group's details (ChatDetails), search in the chat,
 * its starred messages and its media gallery
 * (photos and videos, documents, links). Picking a message asks the chat
 * to show it (`whatsapp-show-message`).
 *
 * Only the chats of the user's numbers.
 *
 * @property-read WhatsappTarget $chat
 * @property-read Collection<int, WhatsappChatMessage> $results
 * @property-read Collection<int, WhatsappChatMessage> $starred
 * @property-read Collection<int, WhatsappChatMessage> $gallery
 */
class ChatInfo extends Component
{
    public const string TAB_DETAILS = 'details';

    public const string TAB_SEARCH = 'search';

    public const string TAB_STARRED = 'starred';

    public const string TAB_MEDIA = 'media';

    /**
     * @var list<string>
     */
    public const array TABS = [self::TAB_DETAILS, self::TAB_SEARCH, self::TAB_STARRED, self::TAB_MEDIA];

    public const int RESULTS_SHOWN = 50;

    public const int GALLERY_PAGE = 60;

    #[Locked]
    public string $chatId;

    public string $tab = self::TAB_SEARCH;

    public string $search = '';

    public string $galleryKind = WhatsappTarget::GALLERY_MEDIA;

    public int $galleryLimit = self::GALLERY_PAGE;

    public function mount(string $chatId, string $tab = self::TAB_SEARCH): void
    {
        $this->chatId = $chatId;
        $this->tab = in_array($tab, self::TABS, true) ? $tab : self::TAB_SEARCH;
        $this->authorize('view', $this->chat->account);
    }

    #[Computed]
    public function chat(): WhatsappTarget
    {
        return WhatsappTarget::query()
            ->where('friendly_id', $this->chatId)
            ->whereHas('account', fn ($query) => $query->where('user_id', $this->user()->id))
            ->firstOrFail();
    }

    /**
     * The messages with every word searched for, newest first.
     *
     * @return Collection<int, WhatsappChatMessage>
     */
    #[Computed]
    public function results(): Collection
    {
        if (mb_strlen(mb_trim($this->search)) < 2) {
            return new Collection;
        }

        return $this->chat->searchMessages($this->search)->limit(self::RESULTS_SHOWN)->get();
    }

    /**
     * @return Collection<int, WhatsappChatMessage>
     */
    #[Computed]
    public function starred(): Collection
    {
        return $this->chat->chatMessages()->whereNotNull('starred_at')->latest('starred_at')->limit(self::RESULTS_SHOWN)->get();
    }

    /**
     * @return Collection<int, WhatsappChatMessage>
     */
    #[Computed]
    public function gallery(): Collection
    {
        $kind = in_array($this->galleryKind, WhatsappTarget::GALLERY_KINDS, true) ? $this->galleryKind : WhatsappTarget::GALLERY_MEDIA;

        return $this->chat->galleryMessages($kind)->limit($this->galleryLimit + 1)->get();
    }

    #[On('whatsapp-info-tab')]
    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : self::TAB_SEARCH;
    }

    public function loadMoreGallery(): void
    {
        $this->galleryLimit += self::GALLERY_PAGE;
    }

    public function unstar(string $messageId, StarWhatsappChatMessage $star): void
    {
        $star($this->user(), $this->chat->chatMessages()->where('friendly_id', $messageId)->firstOrFail(), false);
        unset($this->starred);
        $this->dispatch('whatsapp-message-changed');
    }

    /**
     * Asks the chat to scroll to the message, loading as far back as it is.
     */
    public function show(string $messageId): void
    {
        $this->dispatch('whatsapp-show-message', messageId: $messageId);
    }

    public function render(): View
    {
        return view('livewire.whatsapp.chat-info');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
