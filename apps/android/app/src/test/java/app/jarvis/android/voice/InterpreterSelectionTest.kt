package app.jarvis.android.voice

import app.jarvis.android.R
import app.jarvis.android.i18n.AppLanguage
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.VoiceAction
import app.jarvis.android.network.VoiceActionName
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import java.time.LocalDateTime
import java.time.ZoneId

class InterpreterSelectionTest {
    private val transport = StubTransport()
    private val service = VoiceService(transport.client())
    private val now = LocalDateTime.of(2026, 10, 5, 14, 30)
    private val zone = ZoneId.of("America/Sao_Paulo")
    private val addMilk = VoiceAction(VoiceActionName.ShoppingAddItem, mapOf("item" to "milk"))
    private val done = """{"action":"shopping.add_item","status":"done","message":"Added.","parameters":{"item":"milk","list":null}}"""

    private suspend fun interpret(model: FakeVoiceModel, preference: InterpreterPreference) =
        VoiceInterpreter(service, model).interpret("add milk", preference, AppLanguage.English, now, zone)

    private fun lastBody() = Json.parseToJsonElement(transport.bodies.last()!!).jsonObject

    @Test
    fun `the choice follows the preference and the phone`() {
        assertEquals(InterpreterChoice.Local, InterpreterSelection.choose(InterpreterPreference.Local, isLocalAvailable = true))
        assertEquals(InterpreterChoice.LocalUnavailable, InterpreterSelection.choose(InterpreterPreference.Local, isLocalAvailable = false))
        assertEquals(InterpreterChoice.Remote, InterpreterSelection.choose(InterpreterPreference.Remote, isLocalAvailable = true))
        assertEquals(InterpreterChoice.Remote, InterpreterSelection.choose(InterpreterPreference.Remote, isLocalAvailable = false))
        assertEquals(InterpreterChoice.Local, InterpreterSelection.choose(InterpreterPreference.Automatic, isLocalAvailable = true))
        assertEquals(InterpreterChoice.Remote, InterpreterSelection.choose(InterpreterPreference.Automatic, isLocalAvailable = false))
    }

    @Test
    fun `the preference is automatic until the user picks one`() {
        assertEquals(InterpreterPreference.Automatic, InterpreterPreference.fromStorage(null))
        assertEquals(InterpreterPreference.Automatic, InterpreterPreference.fromStorage("nonsense"))
        InterpreterPreference.entries.forEach { assertEquals(it, InterpreterPreference.fromStorage(it.storageValue)) }
    }

    @Test
    fun `a phone without support falls back to the remote one in automatic mode`() = runTest {
        transport.enqueue(200, done)
        val model = FakeVoiceModel(isAvailable = false, answer = { addMilk })

        val result = interpret(model, InterpreterPreference.Automatic)

        assertEquals("Added.", result.message)
        assertEquals("/api/v1/voice/commands", transport.requests.single().url.encodedPath)
        assertEquals("add milk", lastBody().getValue("text").jsonPrimitive.content)
        assertTrue(model.texts.isEmpty())
    }

    @Test
    fun `a model that throws while checking counts as unavailable`() = runTest {
        transport.enqueue(200, done)
        val model = object : OnDeviceVoiceModel {
            override suspend fun isAvailable(language: AppLanguage): Boolean = error("AICore is missing")

            override suspend fun action(text: String, language: AppLanguage, now: LocalDateTime, zone: ZoneId): VoiceAction? = error("Not called")
        }

        VoiceInterpreter(service, model).interpret("add milk", InterpreterPreference.Automatic, AppLanguage.English, now, zone)

        assertEquals("/api/v1/voice/commands", transport.requests.single().url.encodedPath)
    }

    @Test
    fun `the local one structures the action and the server runs it`() = runTest {
        transport.enqueue(200, done)
        val model = FakeVoiceModel(isAvailable = true, answer = { addMilk })

        interpret(model, InterpreterPreference.Automatic)

        assertEquals(listOf("add milk"), model.texts)
        assertEquals("/api/v1/voice/actions", transport.requests.single().url.encodedPath)
        assertEquals("shopping.add_item", lastBody().getValue("action").jsonPrimitive.content)
        assertEquals("milk", lastBody().getValue("parameters").jsonObject.getValue("item").jsonPrimitive.content)
    }

    @Test
    fun `in automatic mode the server gets a second try when the phone does not understand or fails`() = runTest {
        transport.enqueue(200, done)
        transport.enqueue(200, done)

        interpret(FakeVoiceModel(isAvailable = true, answer = { null }), InterpreterPreference.Automatic)
        interpret(FakeVoiceModel(isAvailable = true, answer = { error("The context window is full") }), InterpreterPreference.Automatic)

        assertEquals(listOf("/api/v1/voice/commands", "/api/v1/voice/commands"), transport.requests.map { it.url.encodedPath })
    }

    @Test
    fun `the local preference never sends the text to the server behind the users back`() = runTest {
        val cases = listOf(
            FakeVoiceModel(isAvailable = false) to VoiceInterpreterException.LocalUnavailable::class,
            FakeVoiceModel(isAvailable = true, answer = { null }) to VoiceInterpreterException.NotUnderstood::class,
            FakeVoiceModel(isAvailable = true, answer = { error("Guardrail") }) to VoiceInterpreterException.LocalFailed::class,
        )

        cases.forEach { (model, expected) ->
            try {
                interpret(model, InterpreterPreference.Local)
                fail("Expected $expected")
            } catch (exception: VoiceInterpreterException) {
                assertEquals(expected, exception::class)
            }
        }

        assertTrue(transport.requests.isEmpty())
    }

    @Test
    fun `the remote preference never runs the model`() = runTest {
        transport.enqueue(200, done)
        val model = FakeVoiceModel(isAvailable = true, answer = { addMilk })

        interpret(model, InterpreterPreference.Remote)

        assertTrue(model.texts.isEmpty())
        assertEquals("/api/v1/voice/commands", transport.requests.single().url.encodedPath)
    }

    @Test
    fun `the server without an AI provider is a friendly error`() = runTest {
        transport.enqueue(503, """{"message":"O provedor de IA não está configurado."}""")

        try {
            interpret(FakeVoiceModel(), InterpreterPreference.Remote)
            fail("Expected a failure")
        } catch (exception: VoiceInterpreterException.Api) {
            assertTrue(exception.exception is ApiException.Server)
            assertEquals("O provedor de IA não está configurado.", exception.exception.displayMessage)
        }
    }

    @Test
    fun `the controller interprets the heard text and speaks the answer in the users language`() = runTest {
        transport.enqueue(200, """{"action":"shopping.add_item","status":"done","message":"Adicionei leite.","parameters":{}}""")
        val speech = FakeSpeechInput()
        val speaker = FakeSpeaker()
        val voice = voiceController(
            backgroundScope,
            transport,
            language = AppLanguage.BrazilianPortuguese,
            speech = speech,
            model = FakeVoiceModel(isAvailable = false),
            speaker = speaker,
        )

        voice.begin(shouldShowRationale = false)
        speech.hear(SpeechOutcome.Heard("adicione leite na lista dessa semana"))
        val answered = voice.awaitPhase { it is VoicePhase.Answered } as VoicePhase.Answered

        assertEquals(VoicePhase.Answered("adicione leite na lista dessa semana", VoiceResponseFlow.Done("Adicionei leite.")), answered)
        assertEquals(listOf("Adicionei leite." to AppLanguage.BrazilianPortuguese), speaker.spoken)
        assertEquals("/api/v1/voice/commands", transport.requests.single().url.encodedPath)
    }

    @Test
    fun `the controller explains a local preference this phone cannot run, aloud`() = runTest {
        val speech = FakeSpeechInput()
        val speaker = FakeSpeaker()
        val voice = voiceController(
            backgroundScope,
            transport,
            speech = speech,
            preferences = InMemoryInterpreterStore(InterpreterPreference.Local),
            speaker = speaker,
        )

        voice.begin(shouldShowRationale = false)
        speech.hear(SpeechOutcome.Heard("add milk"))
        val failed = voice.awaitPhase { it is VoicePhase.Failed } as VoicePhase.Failed

        assertEquals("${R.string.voice_local_unavailable}/en", failed.message)
        assertEquals(failed.message, speaker.spoken.single().first)
        assertTrue(transport.requests.isEmpty())
    }
}
