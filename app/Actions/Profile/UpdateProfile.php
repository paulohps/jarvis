<?php

namespace App\Actions\Profile;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The user edits their own name and avatar (US-3.1). The avatar is a JPG,
 * PNG or WebP image of up to 2MB, stored on the `public` disk under
 * `avatars/`; the previous file is deleted once the new one is saved.
 * Without a new avatar the stored one is kept.
 */
final readonly class UpdateProfile
{
    public const string AVATAR_DIRECTORY = 'avatars';

    /**
     * Largest avatar accepted, in kilobytes.
     */
    public const int AVATAR_MAX_KILOBYTES = 2048;

    /**
     * @param  array{name?: mixed, avatar?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, array $input): User
    {
        /** @var array{name: string, avatar?: UploadedFile|null} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::AVATAR_MAX_KILOBYTES],
        ])->validate();

        $previousAvatar = $user->avatar_path;

        $user->name = trim($validated['name']);

        $avatar = $validated['avatar'] ?? null;

        if ($avatar instanceof UploadedFile) {
            $user->avatar_path = $avatar->store(self::AVATAR_DIRECTORY, config('filesystems.media')) ?: null;
        }

        $user->save();

        if ($previousAvatar !== null && $previousAvatar !== $user->avatar_path) {
            Storage::disk(config('filesystems.media'))->delete($previousAvatar);
        }

        return $user;
    }
}
