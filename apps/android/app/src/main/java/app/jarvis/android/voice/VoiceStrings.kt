package app.jarvis.android.voice

import android.content.Context
import android.content.res.Configuration
import androidx.annotation.StringRes
import app.jarvis.android.i18n.AppLanguage

/**
 * The app's strings in the user's language outside Compose: the voice
 * messages are shown and spoken, so the controller needs them as text.
 */
fun interface VoiceStrings {
    fun get(@StringRes id: Int, language: AppLanguage): String
}

/** [VoiceStrings] of the app's resources (`values`, `values-pt-rBR`). */
class ResourceVoiceStrings(context: Context) : VoiceStrings {
    private val appContext = context.applicationContext
    private val contexts = mutableMapOf<AppLanguage, Context>()

    override fun get(id: Int, language: AppLanguage): String = contexts.getOrPut(language) {
        appContext.createConfigurationContext(Configuration(appContext.resources.configuration).apply { setLocale(language.locale) })
    }.getString(id)
}
