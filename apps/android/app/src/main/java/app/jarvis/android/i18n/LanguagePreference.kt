package app.jarvis.android.i18n

/**
 * The app's own locale, kept by the system across launches (Android's
 * per-app language, `LocaleManager.applicationLocales`).
 */
interface AppLocales {
    /** The tags of the app's locale, empty when it follows the device. */
    fun applicationLanguageTags(): List<String>

    /** Sets the app's locale (an empty list follows the device again); the activities are recreated. */
    fun setApplicationLanguageTags(tags: List<String>)

    /** The device's languages, in the user's order. */
    fun systemLanguageTags(): List<String>
}

/**
 * The language the app speaks. Before the sign-in it follows the device;
 * afterwards it follows the user's preference from `/me` (US-3.2, US-12.2),
 * kept as the app's locale so the next launch already opens in it.
 */
class LanguagePreference(private val locales: AppLocales) {
    /** The app's language, otherwise the device's. */
    val current: AppLanguage
        get() = AppLanguage.matching(locales.applicationLanguageTags().ifEmpty { locales.systemLanguageTags() })

    /**
     * Follows the user's server locale (`/me` → `locale`). The locale is only
     * set when it changes, as setting it recreates the activities.
     */
    fun apply(apiLocale: String): AppLanguage {
        val language = AppLanguage.fromApiLocale(apiLocale)

        if (locales.applicationLanguageTags() != listOf(language.languageTag)) {
            locales.setApplicationLanguageTags(listOf(language.languageTag))
        }

        return language
    }

    /** Back to the device's language (after the sign-out). */
    fun reset() {
        if (locales.applicationLanguageTags().isNotEmpty()) {
            locales.setApplicationLanguageTags(emptyList())
        }
    }
}
