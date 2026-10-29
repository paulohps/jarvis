package app.jarvis.android.i18n

import java.util.Locale

/**
 * The languages of Jarvis (US-3.2): English by default and Brazilian
 * Portuguese. [apiLocale] is the server's locale (`/me` → `locale`) and
 * [languageTag] the BCP 47 tag (the `values-pt-rBR` resources, the
 * `Accept-Language` header and the app's locale).
 */
enum class AppLanguage(val apiLocale: String, val languageTag: String, val nativeName: String) {
    English("en", "en", "English"),
    BrazilianPortuguese("pt_BR", "pt-BR", "Português (Brasil)"),
    ;

    val locale: Locale
        get() = Locale.forLanguageTag(languageTag)

    companion object {
        /** The language of a server locale (`pt_BR`, `pt-BR`); English for any other. */
        fun fromApiLocale(apiLocale: String): AppLanguage =
            when (apiLocale.replace('-', '_').lowercase()) {
                "pt_br", "pt" -> BrazilianPortuguese
                else -> English
            }

        /** The first supported language of a list of BCP 47 tags (the device's, before the sign-in). */
        fun matching(languageTags: List<String>): AppLanguage {
            for (tag in languageTags.map(String::lowercase)) {
                if (tag.startsWith("pt")) {
                    return BrazilianPortuguese
                }

                if (tag.startsWith("en")) {
                    return English
                }
            }

            return English
        }
    }
}
