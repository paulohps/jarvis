package app.jarvis.android.voice

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.VoiceAction
import app.jarvis.android.network.VoiceActionName
import app.jarvis.android.network.VoiceClarification
import app.jarvis.android.network.VoiceCommandResult
import app.jarvis.android.network.VoiceOption
import app.jarvis.android.network.VoiceStatus
import app.jarvis.android.network.WhatsappTargetType
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class VoiceResponseHandlerTest {
    private val transport = StubTransport()
    private val handler = VoiceResponseHandler(VoiceService(transport.client()))

    private fun result(json: String) = ApiClient.json.decodeFromString<VoiceCommandResult>(json)

    private fun lastBody(): JsonObject = Json.parseToJsonElement(transport.bodies.last()!!).jsonObject

    private val clarification = """{"action":"shopping.add_item","status":"needs_clarification","message":"Which list?",
        "parameters":{"item":"leite","quantity":null,"list":"semana"},
        "clarification":{"parameter":"list","options":[{"id":"shl_1","label":"Compras da semana"},{"id":"shl_2","label":"Semana que vem"}]},
        "confirmation":null}"""

    private val confirmation = """{"action":"whatsapp.send","status":"needs_confirmation","message":"Send it?",
        "parameters":{"target":"Ana","body":"Cheguei","account":null},"clarification":null,
        "confirmation":{"token":"tok_1","expires_at":"2026-10-05T14:35:00+00:00",
          "summary":{"account":"Pessoal","target":"Família","target_type":"group","body":"Cheguei"}}}"""

    @Test
    fun `done shows and speaks the message`() {
        assertEquals(
            VoiceResponseFlow.Done("Added."),
            handler.flow(result("""{"action":"shopping.add_item","status":"done","message":"Added.","parameters":{"item":"milk"},"clarification":null,"confirmation":null}""")),
        )
    }

    @Test
    fun `needs_confirmation waits for the tap with what would be sent`() {
        val flow = handler.flow(result(confirmation)) as VoiceResponseFlow.Confirm

        assertEquals("Send it?", flow.message)
        assertEquals("tok_1", flow.confirmation.token)
        assertEquals("Família", flow.confirmation.summary.target)
        assertEquals(WhatsappTargetType.Group, flow.confirmation.summary.targetType)
        assertEquals("Pessoal", flow.confirmation.summary.account)
        assertEquals("Cheguei", flow.confirmation.summary.body)
    }

    @Test
    fun `needs_clarification shows the options`() {
        val flow = handler.flow(result(clarification)) as VoiceResponseFlow.Clarify

        assertEquals("list", flow.clarification.parameter)
        assertEquals(listOf("shl_1", "shl_2"), flow.clarification.options.map { it.id })
    }

    @Test
    fun `forbidden is a refusal and failed is a failure`() {
        assertEquals(
            VoiceResponseFlow.Refused("WhatsApp is off for you."),
            handler.flow(result("""{"action":"whatsapp.send","status":"forbidden","message":"WhatsApp is off for you.","parameters":{}}""")),
        )
        assertEquals(
            VoiceResponseFlow.Failed("I did not understand."),
            handler.flow(result("""{"action":null,"status":"failed","message":"I did not understand.","parameters":null}""")),
        )
    }

    @Test
    fun `an answer missing what its status needs fails`() {
        assertEquals(
            VoiceResponseFlow.Failed("Send it?"),
            handler.flow(result("""{"action":"whatsapp.send","status":"needs_confirmation","message":"Send it?","parameters":{},"confirmation":null}""")),
        )
        assertEquals(
            VoiceResponseFlow.Failed("Which?"),
            handler.flow(result("""{"action":"shopping.add_item","status":"needs_clarification","message":"Which?","clarification":{"parameter":"list","options":[]}}""")),
        )
        assertEquals(
            VoiceResponseFlow.Failed("Which?"),
            handler.flow(VoiceCommandResult(action = null, status = VoiceStatus.NeedsClarification, message = "Which?", clarification = VoiceClarification("list", listOf(VoiceOption("shl_1", "A"))))),
        )
    }

    @Test
    fun `the parameters keep the APIs names and drop the nulls`() {
        val decoded = result(clarification)

        assertEquals(VoiceActionName.ShoppingAddItem, decoded.action)
        assertEquals(mapOf("item" to "leite", "list" to "semana"), decoded.parameters)
    }

    @Test
    fun `picking an option sends the same action with the options id`() = runTest {
        transport.enqueue(200, """{"action":"shopping.add_item","status":"done","message":"Added.","parameters":{}}""")

        val answer = handler.choose(VoiceOption("shl_2", "Semana que vem"), result(clarification))

        assertEquals("/api/v1/voice/actions", transport.requests.single().url.encodedPath)
        assertEquals("shopping.add_item", lastBody().getValue("action").jsonPrimitive.content)
        val parameters = lastBody().getValue("parameters").jsonObject
        assertEquals("shl_2", parameters.getValue("list").jsonPrimitive.content)
        assertEquals("leite", parameters.getValue("item").jsonPrimitive.content)
        assertEquals(VoiceResponseFlow.Done("Added."), handler.flow(answer))
    }

    @Test
    fun `confirming sends the token, once`() = runTest {
        transport.enqueue(200, """{"action":"whatsapp.send","status":"done","message":"Sending.","parameters":{}}""")
        val flow = handler.flow(result(confirmation)) as VoiceResponseFlow.Confirm

        val answer = handler.confirm(flow.confirmation)

        assertEquals("/api/v1/voice/commands/confirm", transport.requests.single().url.encodedPath)
        assertEquals("tok_1", lastBody().getValue("confirmation_token").jsonPrimitive.content)
        assertEquals(VoiceResponseFlow.Done("Sending."), handler.flow(answer))
    }

    @Test
    fun `the sheet picks an option, and a cancel runs nothing`() = runTest {
        transport.enqueue(200, clarification)
        transport.enqueue(200, """{"action":"shopping.add_item","status":"done","message":"Added.","parameters":{}}""")
        transport.enqueue(200, clarification)
        val speaker = FakeSpeaker()
        val voice = voiceController(backgroundScope, transport, speaker = speaker)
        val addMilk = VoiceAction(VoiceActionName.ShoppingAddItem, mapOf("item" to "leite", "list" to "semana"))

        voice.run(addMilk)
        voice.awaitPhase { (it as? VoicePhase.Answered)?.flow is VoiceResponseFlow.Clarify }
        voice.choose(VoiceOption("shl_1", "Compras da semana"))
        voice.awaitPhase { (it as? VoicePhase.Answered)?.flow is VoiceResponseFlow.Done }

        assertEquals("shl_1", lastBody().getValue("parameters").jsonObject.getValue("list").jsonPrimitive.content)
        assertEquals(listOf("Which list?", "Added."), speaker.spoken.map { it.first })

        voice.run(addMilk)
        voice.awaitPhase { (it as? VoicePhase.Answered)?.flow is VoiceResponseFlow.Clarify }
        voice.cancelAnswer()

        val cancelled = (voice.phase.value as VoicePhase.Answered).flow
        assertTrue(cancelled is VoiceResponseFlow.Failed)
        // A pick after the cancel has no clarification to answer.
        voice.choose(VoiceOption("shl_1", "Compras da semana"))
        assertEquals(3, transport.requests.size)
        assertNull((voice.phase.value as VoicePhase.Answered).text)
    }
}
