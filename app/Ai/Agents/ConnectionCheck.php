<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\ReportsAiUsage;
use App\Models\AiProviderConnection;
use App\Models\User;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;
use Stringable;

/**
 * The smallest possible call to a stored AI provider connection (US-2.3,
 * "test connection"): one short prompt, a few output tokens, through the
 * connection's own credentials and model. Its tokens count in the usage
 * report, without a use of the AI.
 */
#[MaxTokens(16)]
#[Timeout(20)]
class ConnectionCheck implements Agent, ReportsAiUsage
{
    use Promptable;

    public const string PROMPT = 'Reply with the single word OK.';

    public function __construct(
        public AiProviderConnection $connection,
        public ?User $user = null,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You check that an API connection works. Answer with the single word OK.';
    }

    /**
     * The connection, built on demand from the stored credentials.
     *
     * @return list<Provider>
     */
    public function provider(): array
    {
        return [Ai::build($this->connection->providerConfig())];
    }

    public function usageConnection(): AiProviderConnection
    {
        return $this->connection;
    }

    public function usagePurpose(): ?string
    {
        return null;
    }

    public function usageUser(): ?User
    {
        return $this->user;
    }
}
