package app.jarvis.android.navigation

import app.jarvis.android.network.UserModule
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

class NavigationBuilderTest {
    private fun module(slug: String, mobile: Boolean = true) = UserModule(slug, slug.replaceFirstChar(Char::uppercase), mobile)

    @Test
    fun `the slugs give the expected tabs, in order, with notifications and settings`() {
        val tabs = NavigationBuilder.tabs(listOf(module("whatsapp"), module("shopping"), module("reminders")))

        assertEquals(
            listOf(AppTab.Shopping, AppTab.Reminders, AppTab.WhatsApp, AppTab.Notifications, AppTab.Settings),
            tabs,
        )
    }

    @Test
    fun `only the modules the user has get a tab`() {
        assertEquals(
            listOf(AppTab.Reminders, AppTab.Notifications, AppTab.Settings),
            NavigationBuilder.tabs(listOf(module("reminders"))),
        )
    }

    @Test
    fun `without modules there are notifications and settings`() {
        assertEquals(listOf(AppTab.Notifications, AppTab.Settings), NavigationBuilder.tabs(emptyList()))
    }

    @Test
    fun `invoices, admin and unknown modules never have a tab`() {
        val tabs = NavigationBuilder.tabs(listOf(module("invoices"), module("admin"), module("voice"), module("shopping")))

        assertEquals(listOf(AppTab.Shopping, AppTab.Notifications, AppTab.Settings), tabs)
        assertFalse(AppTab.entries.any { it.moduleSlug == "invoices" || it.moduleSlug == "admin" })
    }

    @Test
    fun `a module not available on mobile has no tab`() {
        assertEquals(
            listOf(AppTab.Notifications, AppTab.Settings),
            NavigationBuilder.tabs(listOf(module("shopping", mobile = false))),
        )
    }

    @Test
    fun `a module switched off disappears on the refresh and the selection moves`() {
        val before = NavigationBuilder.tabs(listOf(module("shopping"), module("reminders")))
        val after = NavigationBuilder.tabs(listOf(module("reminders")))

        assertEquals(true, AppTab.Shopping in before)
        assertEquals(false, AppTab.Shopping in after)
        assertEquals(AppTab.Reminders, NavigationBuilder.selection(AppTab.Shopping, after))
        assertEquals(AppTab.Notifications, NavigationBuilder.selection(AppTab.Notifications, after))
        assertEquals(AppTab.Reminders, NavigationBuilder.selection(null, after))
    }
}
