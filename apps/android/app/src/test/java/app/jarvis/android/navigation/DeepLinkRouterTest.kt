package app.jarvis.android.navigation

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class DeepLinkRouterTest {
    private val router = DeepLinkRouter("https://jarvis.example.com")

    @Test
    fun `each app deep link resolves the right route`() {
        assertEquals(AppRoute.Reminder("remo_8Kq2Zp0a"), router.route("jarvis://reminders/remo_8Kq2Zp0a"))
        assertEquals(AppRoute.Reminder("rem_1"), router.route("jarvis://reminder-occurrences/rem_1"))
        assertEquals(AppRoute.Tab(AppTab.Reminders), router.route("jarvis://reminders"))
        assertEquals(AppRoute.ShoppingList("shl_1"), router.route("jarvis://shopping/lists/shl_1"))
        assertEquals(AppRoute.ShoppingList("shl_2"), router.route("jarvis://shopping/shl_2"))
        assertEquals(AppRoute.Tab(AppTab.Shopping), router.route("jarvis://shopping"))
        assertEquals(AppRoute.Tab(AppTab.WhatsApp), router.route("jarvis://whatsapp"))
        assertEquals(AppRoute.Tab(AppTab.Notifications), router.route("jarvis://notifications"))
        assertEquals(AppRoute.Tab(AppTab.Settings), router.route("jarvis://settings"))
        assertEquals(AppRoute.Tab(AppTab.Notifications), router.route("jarvis://"))
    }

    @Test
    fun `the same paths on the server's web url resolve the same routes`() {
        assertEquals(AppRoute.Reminder("remo_1"), router.route("https://jarvis.example.com/reminders/remo_1"))
        assertEquals(AppRoute.ShoppingList("shl_1"), router.route("https://JARVIS.example.com/shopping/lists/shl_1?from=push"))
        assertEquals(AppRoute.Tab(AppTab.WhatsApp), router.route("https://jarvis.example.com/whatsapp/messages"))
        assertEquals(AppRoute.Tab(AppTab.Settings), router.route("https://jarvis.example.com/profile"))
    }

    @Test
    fun `the push of a new whatsapp message opens its chat`() {
        assertEquals(
            AppRoute.WhatsappChat("wtgt_8Kq2"),
            router.route("https://jarvis.example.com/whatsapp/chat?account=wacc_1&chat=wtgt_8Kq2"),
        )
        assertEquals(AppRoute.WhatsappChat("wtgt_1"), router.route("jarvis://whatsapp/chat?chat=wtgt_1"))
        assertEquals(AppRoute.Tab(AppTab.WhatsApp), router.route("https://jarvis.example.com/whatsapp/chat"))
        assertEquals(AppRoute.Tab(AppTab.WhatsApp), router.route("jarvis://whatsapp/chat?chat="))
        assertEquals(AppTab.WhatsApp, AppRoute.WhatsappChat("wtgt_1").tab)
    }

    @Test
    fun `web only pages open in the browser`() {
        assertEquals(
            AppRoute.Web("https://jarvis.example.com/invoices/inv_1"),
            router.route("https://jarvis.example.com/invoices/inv_1"),
        )
        assertEquals(AppRoute.Web("https://jarvis.example.com/admin/users"), router.route("https://jarvis.example.com/admin/users"))
        assertNull(router.route("jarvis://invoices/inv_1"))
    }

    @Test
    fun `links of other hosts, unknown paths and junk resolve nothing`() {
        assertNull(router.route("https://evil.example.com/reminders/remo_1"))
        assertNull(router.route("https://jarvis.example.com/"))
        assertNull(router.route("jarvis://unknown/thing"))
        assertNull(router.route("mailto:ana@example.com"))
        assertNull(router.route("not a link"))
        assertNull(router.route(""))
        assertNull(router.route(null))
    }

    @Test
    fun `each route lives in its tab`() {
        assertEquals(AppTab.Reminders, AppRoute.Reminder("remo_1").tab)
        assertEquals(AppTab.Shopping, AppRoute.ShoppingList("shl_1").tab)
        assertNull(AppRoute.Web("https://jarvis.example.com/invoices").tab)
    }
}

class RouterTest {
    private val tabs = listOf(AppTab.Shopping, AppTab.Reminders, AppTab.Notifications, AppTab.Settings)

    @Test
    fun `a deep link opens its tab and screen`() {
        val router = Router().apply { reset(tabs) }

        router.open(AppRoute.Reminder("remo_1"), tabs)

        assertEquals(AppTab.Reminders, router.state.value.selectedTab)
        assertEquals(listOf(Destination.Reminder("remo_1")), router.state.value.stack(AppTab.Reminders))
    }

    @Test
    fun `a whatsapp chat deep link pushes the chat over the whatsapp tab`() {
        val withWhatsapp = tabs + AppTab.WhatsApp
        val router = Router().apply { reset(withWhatsapp) }

        router.open(AppRoute.WhatsappChat("wtgt_1"), withWhatsapp)

        assertEquals(AppTab.WhatsApp, router.state.value.selectedTab)
        assertEquals(listOf(Destination.WhatsappChat("wtgt_1", "")), router.state.value.stack(AppTab.WhatsApp))
    }

    @Test
    fun `a deep link to a module the user no longer has goes nowhere`() {
        val router = Router().apply { reset(tabs) }

        assertEquals(false, router.open(AppRoute.Tab(AppTab.WhatsApp), tabs))
        assertEquals(AppTab.Shopping, router.state.value.selectedTab)
    }
}
