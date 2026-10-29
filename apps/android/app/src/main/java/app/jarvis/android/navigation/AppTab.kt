package app.jarvis.android.navigation

import app.jarvis.android.network.UserModule

/**
 * A tab of the app (US-11.1). The module tabs exist only for the modules the
 * user has; Notifications and Settings are always there. Invoices and the
 * admin area are web only and have no tab.
 */
enum class AppTab(
    /** The module slug of `/me` the tab needs, `null` for the tabs everyone has. */
    val moduleSlug: String?,
    /** The `jarvis://` host and the route of the tab. */
    val route: String,
) {
    Shopping("shopping", "shopping"),
    Reminders("reminders", "reminders"),
    WhatsApp("whatsapp", "whatsapp"),
    Notifications(null, "notifications"),
    Settings(null, "settings"),
}

/** Builds the tabs from the modules of `/me`. */
object NavigationBuilder {
    /**
     * Shopping, Reminders and WhatsApp, in this order, for each module the user
     * has that is available on mobile; then Notifications and Settings. Any
     * other slug (invoices, a module the app does not know yet) has no tab.
     */
    fun tabs(modules: List<UserModule>): List<AppTab> {
        val mobileSlugs = modules.filter { it.isAvailableOnMobile }.map { it.slug }.toSet()

        return AppTab.entries.filter { tab -> tab.moduleSlug == null || tab.moduleSlug in mobileSlugs }
    }

    /** The tab to show after the tabs changed: the same one while it exists, otherwise the first. */
    fun selection(selected: AppTab?, tabs: List<AppTab>): AppTab =
        selected?.takeIf { it in tabs } ?: tabs.firstOrNull() ?: AppTab.Settings
}
