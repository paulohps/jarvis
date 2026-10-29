<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The platform of a device registered for push: a phone running the app,
 * or a browser (the web app, installed or not).
 */
class DevicePlatform extends Lookup
{
    public const string IOS = 'ios';

    public const string ANDROID = 'android';

    public const string WEB = 'web';

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
