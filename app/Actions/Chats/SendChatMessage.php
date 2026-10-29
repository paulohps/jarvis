<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatMessageData;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use App\Services\Chats\OutgoingChatFile;
use App\Services\Chats\OutgoingChatMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sends a message to a conversation as the user, through its provider's
 * driver: a text up to MAX_LENGTH and/or up to MAX_FILES files, in the
 * thread of a message when `threadId` is given and the provider has
 * threads. Refused when the provider cannot send to the conversation
 * (ChatCapabilities).
 *
 * Only the owner of the conversation, with the provider switched on.
 */
final readonly class SendChatMessage
{
    use AuthorizesChats;

    public const int MAX_LENGTH = 4000;

    public const int MAX_FILES = 10;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @param  list<OutgoingChatFile>  $files
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ChatProviderException
     */
    public function __invoke(User $actor, ChatConversation $conversation, ?string $text, array $files = [], ?string $threadId = null): ChatMessageData
    {
        $this->authorizeConversation($actor, $conversation);

        $driver = $this->registry->for($conversation);
        $capabilities = $driver->capabilities($conversation);

        /** @var array{text: string|null, files: list<OutgoingChatFile>, thread_id: string|null} $validated */
        $validated = Validator::make(
            ['text' => filled($text) ? mb_trim((string) $text) : null, 'files' => $files, 'thread_id' => $threadId],
            [
                'text' => ['nullable', 'string', 'max:'.self::MAX_LENGTH, 'required_without:files'],
                'files' => ['array', 'max:'.self::MAX_FILES, $capabilities->files ? 'nullable' : 'prohibited'],
                'thread_id' => ['nullable', 'string', $capabilities->threads ? 'nullable' : 'prohibited'],
            ],
            [
                'text.required_without' => __('Write something or attach a file.'),
                'files.prohibited' => __('This channel does not take files.'),
                'thread_id.prohibited' => __('This channel has no threads.'),
            ],
            ['text' => __('message'), 'files' => __('files')],
        )->validate();

        if (! $capabilities->send) {
            throw ValidationException::withMessages(['text' => __('Nothing can be sent to this conversation right now.')]);
        }

        return $driver->send($conversation, new OutgoingChatMessage((string) $validated['text'], $validated['files'], $validated['thread_id']));
    }
}
