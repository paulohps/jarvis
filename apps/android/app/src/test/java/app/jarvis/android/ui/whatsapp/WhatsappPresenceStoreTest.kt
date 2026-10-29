package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.WhatsappChatPresenceEvent
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class WhatsappPresenceStoreTest {
    private var now = 1_000_000L
    private val store = WhatsappPresenceStore { now }

    private fun event(presence: String, who: String? = null, lastSeen: String? = null, chat: String = "wtgt_ana") =
        WhatsappChatPresenceEvent(accountId = "wacc_1", chatId = chat, presence = presence, who = who, lastSeen = lastSeen)

    @Test
    fun `typing fades after a few seconds, online after minutes, the last seen stays`() {
        store.receive(event("composing"))
        assertEquals(WhatsappPresenceStatus.Typing(null), store.status("wtgt_ana"))

        now += WhatsappPresenceStore.ACTIVITY_MS
        assertEquals(WhatsappPresenceStatus.Online, store.status("wtgt_ana"))

        now += WhatsappPresenceStore.ONLINE_MS
        assertNull(store.status("wtgt_ana"))

        store.receive(event("unavailable", lastSeen = "2026-10-05T11:00:00+00:00"))
        store.receive(event("recording"))
        assertEquals(WhatsappPresenceStatus.Recording(null), store.status("wtgt_ana"))

        now += WhatsappPresenceStore.ONLINE_MS
        assertEquals(WhatsappPresenceStatus.LastSeen("2026-10-05T11:00:00+00:00"), store.status("wtgt_ana"))
        assertNull(store.status("wtgt_other"))
    }

    @Test
    fun `in a group only who types shows, named`() {
        store.receive(event("composing", who = "Carla", chat = "wtgt_family"))
        assertEquals(WhatsappPresenceStatus.Typing("Carla"), store.status("wtgt_family"))

        store.receive(event("available", who = "Carla", chat = "wtgt_family"))
        assertNull(store.status("wtgt_family"))
    }
}
