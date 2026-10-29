package app.jarvis.android.support

import app.jarvis.android.i18n.AppLocales

/** The system's per-app locale, in memory. */
class FakeAppLocales(var app: List<String> = emptyList(), var system: List<String> = listOf("en-US")) : AppLocales {
    var writes = 0

    override fun applicationLanguageTags() = app

    override fun setApplicationLanguageTags(tags: List<String>) {
        writes++
        app = tags
    }

    override fun systemLanguageTags() = system
}
