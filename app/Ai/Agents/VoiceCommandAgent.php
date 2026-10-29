<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\ReportsAiUsage;
use App\Ai\Tools\VoiceActionTool;
use App\Exceptions\AiProviderNotConfigured;
use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\User;
use App\Services\Ai\AiRoute;
use App\Services\Voice\VoiceAction;
use App\Services\Voice\VoiceInterpretation;
use Carbon\CarbonImmutable;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;
use Stringable;

/**
 * The remote voice interpreter (US-10.2, US-2.3; Workflow 13): maps a
 * spoken text to one VoiceAction through the AI connection picked for voice
 * commands (AiRoute: otherwise the connection in use),
 * with one tool per action. The tools only record the action; it is run
 * afterwards by ExecuteVoiceAction, as for the app's on-device interpreter.
 *
 * The instructions carry the user's language, for the reply; the prompt
 * carries, after the command, the current date and time in the user's
 * timezone, so "amanhã às 9h" becomes a local `due_at` that CreateReminder
 * reads in that same timezone. The time stays out of the instructions so
 * that they and the tools are the same from one command to the next: the
 * provider's prompt cache (explicit on Anthropic, automatic on OpenAI,
 * DeepSeek, Gemini…) can then reuse them.
 */
#[CacheToolDefinitions]
#[CacheInstructions]
#[MaxSteps(3)]
#[MaxTokens(512)]
#[Timeout(30)]
class VoiceCommandAgent implements Agent, HasTools, ReportsAiUsage
{
    use Promptable;

    private ?VoiceAction $action = null;

    public function __construct(
        public User $user,
        public AiRoute $route,
    ) {}

    /**
     * The interpreter for the user, through the connection picked for it.
     *
     * @throws AiProviderNotConfigured when nothing was picked for it and no connection is in use
     */
    public static function forUser(User $user): self
    {
        return new self($user, AiRoute::for(AiPurpose::VOICE_COMMAND) ?? throw new AiProviderNotConfigured);
    }

    /**
     * Interprets the text: the action the model called, if any, and its reply.
     */
    public function interpret(string $text): VoiceInterpretation
    {
        $this->action = null;

        $response = $this->prompt($this->promptFor($text));

        return new VoiceInterpretation($this->action, trim($response->text));
    }

    /**
     * The command as the model gets it: the spoken text, then the current
     * date and time in the user's timezone.
     */
    public function promptFor(string $text): string
    {
        $now = CarbonImmutable::now($this->user->timezone);

        return implode("\n\n", [
            trim($text),
            "(Now is {$now->format('l, Y-m-d H:i')} in the user's timezone ({$this->user->timezone}).)",
        ]);
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $this->user->loadMissing('locale');

        return implode("\n", [
            'You are Jarvis, the voice assistant of a family. You turn one spoken command into exactly one tool call.',
            '- Call at most one tool, once. Never call a tool the user did not ask for.',
            '- Keep names (lists, items, reminders, contacts, groups) as the user said them; never invent or translate them.',
            '- Write the item, reminder title and message body in the language the user spoke.',
            '- After the command comes the current date and time in the user\'s timezone ("Now is …").',
            '  Resolve relative dates and times ("amanhã às 9h", "tomorrow at 9am", "next Monday") against it and send `due_at` as a local YYYY-MM-DDTHH:MM, without offset.',
            '- When the command matches no tool, call none and say in one sentence what you can do: add or check shopping items, create or complete reminders, send WhatsApp messages.',
            "- Reply with one short sentence in the user's language: {$this->user->locale->name} ({$this->user->locale->slug}).",
        ]);
    }

    /**
     * The connection picked for this use, built on demand from the stored
     * credentials.
     *
     * @return list<Provider>
     */
    public function provider(): array
    {
        return [Ai::build($this->route->providerConfig())];
    }

    public function usageConnection(): AiProviderConnection
    {
        return $this->route->connection;
    }

    public function usagePurpose(): ?string
    {
        return $this->route->purpose;
    }

    public function usageUser(): ?User
    {
        return $this->user;
    }

    /**
     * One tool per voice action.
     *
     * @return list<VoiceActionTool>
     */
    public function tools(): iterable
    {
        return array_map(
            fn (string $action): VoiceActionTool => new VoiceActionTool($action, $this->record(...)),
            VoiceAction::names(),
        );
    }

    /**
     * Keeps the first action the model called; any further call is refused.
     */
    private function record(VoiceAction $action): string
    {
        if ($this->action !== null) {
            return 'Refused: one action per command, and one was already taken. Do not call any other tool.';
        }

        $this->action = $action;

        return 'Recorded. Do not call any other tool; reply with one short sentence.';
    }
}
