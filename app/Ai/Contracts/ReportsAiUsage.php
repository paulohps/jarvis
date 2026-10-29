<?php

namespace App\Ai\Contracts;

use App\Models\AiProviderConnection;
use App\Models\User;

/**
 * An agent whose calls RecordAiUsage writes down: the connection they went
 * through, the use of the AI (an AiPurpose slug, null for none, as the
 * connection test) and the user they were made for.
 */
interface ReportsAiUsage
{
    public function usageConnection(): AiProviderConnection;

    public function usagePurpose(): ?string;

    public function usageUser(): ?User;
}
