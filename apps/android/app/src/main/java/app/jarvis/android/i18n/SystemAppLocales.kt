package app.jarvis.android.i18n

import android.app.LocaleManager
import android.content.Context
import android.os.LocaleList

/** [AppLocales] on the system's `LocaleManager` (per-app language, `res/xml/locales_config.xml`). */
class SystemAppLocales(context: Context) : AppLocales {
    private val manager = context.getSystemService(LocaleManager::class.java)

    override fun applicationLanguageTags(): List<String> = manager.applicationLocales.toTags()

    override fun setApplicationLanguageTags(tags: List<String>) {
        manager.applicationLocales = LocaleList.forLanguageTags(tags.joinToString(","))
    }

    override fun systemLanguageTags(): List<String> = manager.systemLocales.toTags()

    private fun LocaleList.toTags(): List<String> = (0 until size()).map { get(it).toLanguageTag() }
}
