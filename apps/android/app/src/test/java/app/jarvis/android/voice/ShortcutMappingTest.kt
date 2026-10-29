package app.jarvis.android.voice

import app.jarvis.android.BuildConfig
import app.jarvis.android.network.VoiceAction
import app.jarvis.android.network.VoiceActionName
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.w3c.dom.Element
import java.io.File
import java.time.LocalDateTime
import javax.xml.parsers.DocumentBuilderFactory

class ShortcutMappingTest {
    private val now = LocalDateTime.of(2026, 10, 5, 14, 30)

    private fun map(capability: AppActionCapability, vararg extras: Pair<String, String?>) =
        ShortcutMapping.voiceAction(capability, mapOf(*extras), now)

    @Test
    fun `adding a shopping item maps to shopping add_item`() {
        assertEquals(
            VoiceAction(VoiceActionName.ShoppingAddItem, mapOf("item" to "leite", "list" to "dessa semana")),
            map(AppActionCapability.AddShoppingItem, "item" to " leite ", "list" to "dessa semana"),
        )
    }

    @Test
    fun `a list named only shopping list is left to the server`() {
        assertEquals(
            VoiceAction(VoiceActionName.ShoppingAddItem, mapOf("item" to "milk")),
            map(AppActionCapability.AddShoppingItem, "item" to "milk", "list" to "Shopping List"),
        )
        assertEquals(
            VoiceAction(VoiceActionName.ShoppingAddItem, mapOf("item" to "leite")),
            map(AppActionCapability.AddShoppingItem, "item" to "leite", "list" to "lista de compras"),
        )
    }

    @Test
    fun `creating a reminder maps to reminders create on the users wall clock`() {
        assertEquals(
            VoiceAction(VoiceActionName.RemindersCreate, mapOf("title" to "pay the rent", "due_at" to "2026-10-06T09:00")),
            map(AppActionCapability.CreateReminder, "title" to "pay the rent", "date" to "2026-10-06", "time" to "09:00:00"),
        )
        assertEquals(
            VoiceAction(VoiceActionName.RemindersCreate, mapOf("title" to "dentist", "due_at" to "2026-10-07T09:00")),
            map(AppActionCapability.CreateReminder, "title" to "dentist", "date" to "2026-10-07"),
        )
        // A time alone: today, or tomorrow when it already passed.
        assertEquals("2026-10-05T18:00", map(AppActionCapability.CreateReminder, "title" to "gym", "time" to "18:00")?.parameters?.get("due_at"))
        assertEquals("2026-10-06T08:00", map(AppActionCapability.CreateReminder, "title" to "gym", "time" to "08:00")?.parameters?.get("due_at"))
        assertEquals(
            VoiceAction(VoiceActionName.RemindersCreate, mapOf("title" to "call mom")),
            map(AppActionCapability.CreateReminder, "title" to "call mom"),
        )
    }

    @Test
    fun `completing a reminder maps to reminders complete`() {
        assertEquals(
            VoiceAction(VoiceActionName.RemindersComplete, mapOf("reminder" to "pay the rent")),
            map(AppActionCapability.CompleteReminder, "reminder" to "pay the rent"),
        )
    }

    @Test
    fun `sending a message maps to whatsapp send`() {
        assertEquals(
            VoiceAction(VoiceActionName.WhatsappSend, mapOf("target" to "Ana", "body" to "I am on my way")),
            map(AppActionCapability.SendWhatsappMessage, "target" to "Ana", "body" to "I am on my way"),
        )
    }

    @Test
    fun `a missing required value maps to nothing and the microphone opens instead`() {
        assertNull(map(AppActionCapability.AddShoppingItem, "list" to "feira"))
        assertNull(map(AppActionCapability.CreateReminder, "title" to "  "))
        assertNull(map(AppActionCapability.CompleteReminder))
        assertNull(map(AppActionCapability.SendWhatsappMessage, "target" to "Ana"))
    }

    @Test
    fun `each capability is found by its intent action`() {
        AppActionCapability.entries.forEach { assertEquals(it, AppActionCapability.forIntentAction(it.intentAction)) }
        assertNull(AppActionCapability.forIntentAction("android.intent.action.MAIN"))
        assertNull(AppActionCapability.forIntentAction(null))
    }

    @Test
    fun `shortcuts xml declares each capability with its intent action and keys`() {
        val document = DocumentBuilderFactory.newInstance().apply { isNamespaceAware = true }
            .newDocumentBuilder()
            .parse(File("src/main/res/xml/shortcuts.xml"))
        val android = "http://schemas.android.com/apk/res/android"
        val capabilities = document.getElementsByTagName("capability").let { nodes -> (0 until nodes.length).map { nodes.item(it) as Element } }

        assertEquals(AppActionCapability.entries.map { it.capabilityName }.toSet(), capabilities.map { it.getAttributeNS(android, "name") }.toSet())

        capabilities.forEach { element ->
            val capability = AppActionCapability.entries.single { it.capabilityName == element.getAttributeNS(android, "name") }
            val intent = element.getElementsByTagName("intent").item(0) as Element
            val parameters = intent.getElementsByTagName("parameter")
            val keys = (0 until parameters.length).map { (parameters.item(it) as Element).getAttributeNS(android, "key") }.toSet()

            assertEquals(capability.intentAction, intent.getAttributeNS(android, "action"))
            assertEquals(BuildConfig.APPLICATION_ID, intent.getAttributeNS(android, "targetPackage"))
            assertEquals("app.jarvis.android.MainActivity", intent.getAttributeNS(android, "targetClass"))
            assertEquals(capability.keys, keys)
        }
    }

    @Test
    fun `each capability opens its flow and a WhatsApp send waits for the confirmation`() = runTest {
        val transport = StubTransport()
        val voice = voiceController(backgroundScope, transport)
        transport.enqueue(
            200,
            """{"action":"whatsapp.send","status":"needs_confirmation","message":"Send it?","parameters":{"target":"Ana","body":"Hi","account":null},
            "clarification":null,"confirmation":{"token":"tok_1","expires_at":"2026-10-05T14:35:00+00:00",
            "summary":{"account":"Personal","target":"Ana","target_type":"contact","body":"Hi"}}}""",
        )

        voice.run(map(AppActionCapability.SendWhatsappMessage, "target" to "Ana", "body" to "Hi")!!)
        voice.awaitPhase { it is VoicePhase.Answered }

        assertTrue(voice.isPresented.value)
        assertEquals("/api/v1/voice/actions", transport.requests.single().url.encodedPath)
        val body = Json.parseToJsonElement(transport.bodies.single()!!).jsonObject
        assertEquals("whatsapp.send", body.getValue("action").jsonPrimitive.content)
        val flow = (voice.phase.value as VoicePhase.Answered).flow
        assertTrue(flow is VoiceResponseFlow.Confirm)

        // Nothing else is sent until the tap on Send; cancelling sends nothing.
        voice.cancelAnswer()
        voice.confirm()
        assertEquals(1, transport.requests.size)

        transport.enqueue(200, """{"action":"whatsapp.send","status":"needs_confirmation","message":"Send it?","parameters":{},
            "confirmation":{"token":"tok_2","summary":{"account":"Personal","target":"Ana","body":"Hi"}}}""")
        transport.enqueue(200, """{"action":"whatsapp.send","status":"done","message":"Sent.","parameters":{}}""")
        voice.run(map(AppActionCapability.SendWhatsappMessage, "target" to "Ana", "body" to "Hi")!!)
        voice.awaitPhase { (it as? VoicePhase.Answered)?.flow is VoiceResponseFlow.Confirm }
        voice.confirm()
        voice.awaitPhase { (it as? VoicePhase.Answered)?.flow is VoiceResponseFlow.Done }

        assertEquals("/api/v1/voice/commands/confirm", transport.requests.last().url.encodedPath)
        assertEquals("tok_2", Json.parseToJsonElement(transport.bodies.last()!!).jsonObject.getValue("confirmation_token").jsonPrimitive.content)
        assertEquals(VoiceResponseFlow.Done("Sent."), (voice.phase.value as VoicePhase.Answered).flow)
    }
}
