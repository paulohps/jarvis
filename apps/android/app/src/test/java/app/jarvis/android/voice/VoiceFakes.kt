package app.jarvis.android.voice

import app.jarvis.android.i18n.AppLanguage
import app.jarvis.android.network.VoiceAction
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeout
import java.time.LocalDateTime
import java.time.ZoneId

/** `RECORD_AUDIO` as the test sets it. */
class FakeMicrophonePermission(var granted: Boolean = false, var asked: Boolean = false) : MicrophonePermission {
    override fun isGranted(): Boolean = granted

    override fun wasAsked(): Boolean = asked

    override fun markAsked() {
        asked = true
    }
}

/** A recognizer the test ends with [hear]. */
class FakeSpeechInput(var isAvailable: Boolean = true) : SpeechInput {
    override val transcript: StateFlow<String> = MutableStateFlow("")
    override val isListening = MutableStateFlow(false)

    var startedLanguage: AppLanguage? = null
    private var onFinish: ((SpeechOutcome) -> Unit)? = null

    override fun start(language: AppLanguage, onFinish: (SpeechOutcome) -> Unit): Boolean {
        if (!isAvailable) {
            return false
        }

        startedLanguage = language
        this.onFinish = onFinish
        isListening.value = true

        return true
    }

    fun hear(outcome: SpeechOutcome) {
        isListening.value = false
        onFinish?.invoke(outcome)
        onFinish = null
    }

    override fun finish() = Unit

    override fun cancel() {
        onFinish = null
        isListening.value = false
    }
}

/** The strings as `name/language`, so a test reads which message was picked. */
val fakeVoiceStrings = VoiceStrings { id, language -> "$id/${language.languageTag}" }

/** Waits for the phase a controller's call (on OkHttp's thread) leads to. */
suspend fun VoiceController.awaitPhase(predicate: (VoicePhase) -> Boolean): VoicePhase =
    withContext(Dispatchers.Default) {
        withTimeout(5_000) { phase.first(predicate) }
    }

/** An on-device model the test sets: its availability, and the action (or the failure) it answers. */
class FakeVoiceModel(
    var isAvailable: Boolean = false,
    var answer: () -> VoiceAction? = { null },
) : OnDeviceVoiceModel {
    val texts = mutableListOf<String>()

    override suspend fun isAvailable(language: AppLanguage): Boolean = isAvailable

    override suspend fun action(text: String, language: AppLanguage, now: LocalDateTime, zone: ZoneId): VoiceAction? {
        texts += text

        return answer()
    }
}

class InMemoryInterpreterStore(initial: InterpreterPreference = InterpreterPreference.Automatic) : InterpreterPreferenceStore {
    override val preference = MutableStateFlow(initial)

    override fun save(preference: InterpreterPreference) {
        this.preference.value = preference
    }
}

/** What was spoken, in order. */
class FakeSpeaker : VoiceSpeaker {
    val spoken = mutableListOf<Pair<String, AppLanguage>>()

    override fun speak(text: String, language: AppLanguage) {
        spoken += text to language
    }

    override fun stop() = Unit
}

/** A controller on fakes, its calls on [transport]. */
fun voiceController(
    scope: CoroutineScope,
    transport: StubTransport = StubTransport(),
    language: AppLanguage = AppLanguage.English,
    permission: MicrophonePermission = FakeMicrophonePermission(granted = true),
    speech: SpeechInput = FakeSpeechInput(),
    model: OnDeviceVoiceModel = FakeVoiceModel(),
    preferences: InterpreterPreferenceStore = InMemoryInterpreterStore(),
    speaker: VoiceSpeaker = FakeSpeaker(),
): VoiceController {
    val service = VoiceService(transport.client())

    return VoiceController(
        scope = scope,
        currentLanguage = { language },
        awaitSignedIn = { true },
        currentZone = { ZoneId.of("America/Sao_Paulo") },
        service = service,
        interpreter = VoiceInterpreter(service, model),
        preferences = preferences,
        permissions = VoicePermissions(permission),
        speech = speech,
        speaker = speaker,
        strings = fakeVoiceStrings,
    )
}
