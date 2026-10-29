package app.jarvis.android.app

import app.jarvis.android.BuildConfig

/** The settings of this build, from `jarvis.properties` (see `app/build.gradle.kts`). */
data class AppConfiguration(
    val apiBaseUrl: String,
    val reverbHost: String,
    val reverbPort: Int,
    val reverbUseTls: Boolean,
    val reverbAppKey: String,
    val googleServerClientId: String?,
) {
    companion object {
        val current = AppConfiguration(
            apiBaseUrl = BuildConfig.API_BASE_URL,
            reverbHost = BuildConfig.REVERB_HOST,
            reverbPort = BuildConfig.REVERB_PORT,
            reverbUseTls = BuildConfig.REVERB_USE_TLS,
            reverbAppKey = BuildConfig.REVERB_APP_KEY,
            googleServerClientId = BuildConfig.GOOGLE_SERVER_CLIENT_ID.ifBlank { null },
        )
    }
}
