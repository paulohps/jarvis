<?php

namespace App\Actions\Workspaces;

use App\Models\Module;
use App\Models\Workspace;
use Illuminate\Validation\Rule;

/**
 * The fields of a workspace and of a membership, shared by the workspace
 * Actions.
 */
trait ValidatesWorkspace
{
    /**
     * @return list<mixed>
     */
    private function colorRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'string', Rule::in(array_keys(Workspace::COLORS))];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function moduleRules(): array
    {
        return [
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', 'distinct', Rule::exists('modules', 'slug')->where('is_active', true)],
        ];
    }

    /**
     * The ids of the module slugs.
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function moduleIds(array $slugs): array
    {
        return array_values(array_map(static fn (string $slug): int => Module::idFor($slug), $slugs));
    }
}
