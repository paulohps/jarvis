<?php

namespace App\Actions\Whatsapp;

use App\Actions\Recurrences\SaveRecurrence;
use App\Jobs\DeliverWhatsappMessage;
use App\Models\AiPurpose;
use App\Models\User;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappMessage;
use App\Services\Ai\AiRoute;
use App\Services\Whatsapp\WhatsappChatMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Saves a WhatsApp message and its schedule in one transaction, for
 * ScheduleWhatsappMessage and UpdateWhatsappMessage: the fields of
 * WhatsappMessageFields, plus `recurrence`, the input of SaveRecurrence —
 * `once` with a future `starts_at`, or a repeating preset — whose errors
 * come back under `recurrence.*`. A refused schedule saves nothing.
 *
 * A `file` (an UploadedFile, up to SendWhatsappChatMessage::MAX_FILE_KILOBYTES)
 * makes it send that file, the body its caption (then optional); an audio
 * goes as a voice note, which has no caption. On an update the file kept
 * stays unless `remove_media` or another file replaces it. The file waits on
 * the private disk (WhatsappChatMedia).
 *
 * An `ai_prompt` (up to MAX_AI_PROMPT characters) makes the AI write the
 * message at each run instead (WhatsappMessage::$ai_prompt): no body and no
 * file then, and an AI must be set up for WhatsApp replies. A blank one
 * goes back to the body; left out of the input, the saved one stays.
 *
 * A `poll` (`question`, `options`, `multiple`; the rules of
 * SendWhatsappChatContent::pollOf()) makes it send that poll instead, to
 * every target: no body, file or prompt then, the question kept as the
 * body. Its errors come under `poll.*`. Null or empty goes back to the
 * body; left out of the input, the saved one stays.
 */
trait SavesWhatsappMessage
{
    public const int MAX_AI_PROMPT = 2000;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function persist(SaveRecurrence $saveRecurrence, User $actor, array $input, ?WhatsappMessage $message): WhatsappMessage
    {
        $aiPrompt = $this->aiPrompt($input, $message);
        $poll = $this->poll($input, $message);
        $file = $input['file'] ?? null;
        $file = $file instanceof UploadedFile ? $file : null;
        $keepsMedia = $aiPrompt === null && $poll === null && $file === null && $message?->media_path !== null && ! filter_var($input['remove_media'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($aiPrompt !== null && $file !== null) {
            throw ValidationException::withMessages(['file' => __('A message the AI writes goes without a file.')]);
        }

        if ($poll !== null && $aiPrompt !== null) {
            throw ValidationException::withMessages(['poll' => __('A poll is not written by the AI. Turn one of them off.')]);
        }

        if ($poll !== null && $file !== null) {
            throw ValidationException::withMessages(['file' => __('A poll goes without a file.')]);
        }

        $fields = WhatsappMessageFields::validate($actor, [...$input, 'has_media' => $file !== null || $keepsMedia, 'writes_with_ai' => $aiPrompt !== null, 'sends_poll' => $poll !== null]);

        Validator::make(['file' => $file], ['file' => ['nullable', 'file', 'max:'.SendWhatsappChatMessage::MAX_FILE_KILOBYTES]], attributes: ['file' => __('file')])->validate();

        $mime = $file !== null ? self::mimeOf($file) : ($keepsMedia ? (string) $message?->media_mime : null);

        if ($mime !== null && DeliverWhatsappMessage::mediaTypeFor($mime) === 'audio' && $fields->body !== '') {
            throw ValidationException::withMessages(['body' => __('A voice note goes without text. Leave the message empty or schedule the text apart.')]);
        }

        Validator::make($input, ['recurrence' => ['required', 'array']], attributes: ['recurrence' => __('schedule')])->validate();

        /** @var array<string, mixed> $recurrenceInput */
        $recurrenceInput = $input['recurrence'];

        $previousPath = $message?->media_path;
        $media = match (true) {
            $file !== null => [
                'media_path' => WhatsappChatMedia::storeOutgoing($fields->account->friendly_id, (string) $file->get(), (string) $mime),
                'media_mime' => $mime,
                'media_name' => mb_substr($file->getClientOriginalName(), 0, 255) ?: null,
                'media_size' => $file->getSize() ?: null,
            ],
            $keepsMedia => [],
            default => ['media_path' => null, 'media_mime' => null, 'media_name' => null, 'media_size' => null],
        };

        $saved = DB::transaction(function () use ($saveRecurrence, $actor, $fields, $aiPrompt, $poll, $recurrenceInput, $message, $media): WhatsappMessage {
            $message ??= new WhatsappMessage(['user_id' => $actor->id]);

            $message->fill([
                'whatsapp_account_id' => $fields->account->id,
                'body' => match (true) {
                    $poll !== null => $poll['name'],
                    $aiPrompt !== null => '',
                    default => $fields->body,
                },
                'ai_prompt' => $aiPrompt,
                'poll' => $poll,
                ...$media,
            ])->save();

            $message->targets()->sync($fields->targets()->modelKeys());
            $message->setRelation('user', $actor);

            try {
                $saveRecurrence($message, $recurrenceInput);
            } catch (ValidationException $exception) {
                $errors = [];

                foreach ($exception->errors() as $field => $messages) {
                    $errors["recurrence.{$field}"] = $messages;
                }

                throw ValidationException::withMessages($errors);
            }

            return $message->unsetRelation('recurrence')->unsetRelation('targets')->unsetRelation('account');
        });

        if ($previousPath !== null && $previousPath !== $saved->media_path) {
            $this->forgetMedia($previousPath);
        }

        return $saved;
    }

    /**
     * What the AI is asked to write, validated, or null when the message has
     * a body: the input's, else the saved one when the input leaves it out.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function aiPrompt(array $input, ?WhatsappMessage $message): ?string
    {
        if (! array_key_exists('ai_prompt', $input)) {
            return $message?->ai_prompt;
        }

        $prompt = is_string($input['ai_prompt']) ? trim($input['ai_prompt']) : $input['ai_prompt'];

        if ($prompt === null || $prompt === '') {
            return null;
        }

        Validator::make(['ai_prompt' => $prompt], ['ai_prompt' => ['string', 'max:'.self::MAX_AI_PROMPT]], attributes: ['ai_prompt' => __('prompt')])->validate();

        if (AiRoute::for(AiPurpose::WHATSAPP_REPLY) === null) {
            throw ValidationException::withMessages(['ai_prompt' => __('No AI is set up for WhatsApp replies. Ask the administrator to set one.')]);
        }

        /** @var string $prompt */
        return $prompt;
    }

    /**
     * The poll to send, validated, or null when the message has a body:
     * the input's, else the saved one when the input leaves it out.
     *
     * @param  array<string, mixed>  $input
     * @return array{name: string, options: list<string>, selectable_count: int}|null
     *
     * @throws ValidationException
     */
    private function poll(array $input, ?WhatsappMessage $message): ?array
    {
        if (! array_key_exists('poll', $input)) {
            return $message?->poll;
        }

        $poll = $input['poll'];

        if ($poll === null || $poll === []) {
            return null;
        }

        if (! is_array($poll)) {
            throw ValidationException::withMessages(['poll' => __('Send the poll with a question and its options.')]);
        }

        try {
            return SendWhatsappChatContent::pollOf(
                $poll['question'] ?? null,
                is_array($poll['options'] ?? null) ? $poll['options'] : [],
                filter_var($poll['multiple'] ?? false, FILTER_VALIDATE_BOOLEAN),
            );
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors["poll.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The type of the file: what its content says, but an audio recorded in
     * a browser or an app (WebM, MP4) reads as a video, so the type the
     * upload names wins when it is an audio.
     */
    private static function mimeOf(UploadedFile $file): string
    {
        $detected = $file->getMimeType() ?? 'application/octet-stream';
        $named = strtok((string) $file->getClientMimeType(), ';') ?: '';

        return str_starts_with($named, 'audio/') && in_array($detected, ['video/webm', 'video/mp4', 'application/octet-stream', 'audio/webm', 'audio/mp4', 'audio/x-m4a', 'audio/ogg'], true)
            ? $named
            : $detected;
    }

    /**
     * Removes a file no longer scheduled, unless a delivery sent it (then
     * it is the chat's copy, which PruneWhatsappChatMedia frees).
     */
    private function forgetMedia(string $path): void
    {
        if (! WhatsappDelivery::query()->where('media_path', $path)->exists()) {
            WhatsappChatMedia::disk()->delete($path);
        }
    }
}
