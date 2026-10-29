package app.jarvis.android.i18n

import app.jarvis.android.support.FakeAppLocales
import org.junit.Assert.assertEquals
import org.junit.Test

class LanguagePreferenceTest {
    @Test
    fun `before the sign-in the app follows the device`() {
        assertEquals(AppLanguage.BrazilianPortuguese, LanguagePreference(FakeAppLocales(system = listOf("pt-PT", "en-US"))).current)
        assertEquals(AppLanguage.English, LanguagePreference(FakeAppLocales(system = listOf("de-DE"))).current)
    }

    @Test
    fun `the locale of me becomes the app language`() {
        val locales = FakeAppLocales(system = listOf("en-US"))
        val preference = LanguagePreference(locales)

        assertEquals(AppLanguage.BrazilianPortuguese, preference.apply("pt_BR"))
        assertEquals(listOf("pt-BR"), locales.app)
        assertEquals(AppLanguage.BrazilianPortuguese, preference.current)
    }

    @Test
    fun `the same locale is not set again, which would recreate the screens`() {
        val locales = FakeAppLocales(app = listOf("pt-BR"))

        LanguagePreference(locales).apply("pt_BR")

        assertEquals(0, locales.writes)
    }

    @Test
    fun `the sign-out goes back to the device language`() {
        val locales = FakeAppLocales(app = listOf("pt-BR"), system = listOf("en-GB"))
        val preference = LanguagePreference(locales)

        preference.reset()

        assertEquals(emptyList<String>(), locales.app)
        assertEquals(AppLanguage.English, preference.current)
    }

    @Test
    fun `server locales map to the app languages`() {
        assertEquals(AppLanguage.BrazilianPortuguese, AppLanguage.fromApiLocale("pt-BR"))
        assertEquals(AppLanguage.English, AppLanguage.fromApiLocale("en"))
        assertEquals(AppLanguage.English, AppLanguage.fromApiLocale("fr"))
    }
}
