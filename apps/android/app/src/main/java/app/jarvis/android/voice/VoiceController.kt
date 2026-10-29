package app.jarvis.android.voice

import app.jarvis.android.R
import app.jarvis.android.app.SessionListener
import app.jarvis.android.i18n.AppLanguage
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.VoiceAction
import app.jarvis.android.network.VoiceCommandResult
import app.jarvis.android.network.VoiceOption
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.time.Clock
import java.time.LocalDateTime
import java.time.ZoneId

/** Where a voice command is, as the voice sheet shows it. */
sealed interface VoicePhase {
    data object Idle : VoicePhase

    data object Listening : VoicePhase

    /** The system prompt of `RECORD_AUDIO` is to be shown. */
    data object RequestingPermission : VoicePhase

    /** The microphone is refused: the sheet explains, and offers the prompt again or the Android settings. */
    data class AccessDenied(val canAskAgain: Boolean) : VoicePhase

    /** Nothing was heard, the recognizer is not available, or the call failed; in the user's language. */
    data class Failed(val message: String) : VoicePhase

    /** The text goes to the interpreter; `null` for an App Action (nothing was heard). */
    data class Interpreting(val text: String?) : VoicePhase

    /** The answer of the server, and the text it came from. */
    data class Answered(val text: String?, val flow: VoiceResponseFlow) : VoicePhase
}

/**
 * The voice commands (US-10.1 to US-10.3): the microphone button asks
 * `RECORD_AUDIO`, listens with the `SpeechRecognizer` in the user's language
 * and interprets the text locally or remotely (the Settings' preference); an
 * App Action runs its action directly. The answer is shown and spoken
 * (`TextToSpeech`) and goes to its flow: the options of a clarification or
 * the confirmation of a WhatsApp send, which is sent only on the tap on Send.
 */
class VoiceController(
    private val scope: CoroutineScope,
    private val currentLanguage: () -> AppLanguage,
    /** Waits for the saved session to be read; `false` when signed out. */
    private val awaitSignedIn: suspend () -> Boolean,
    /** The user's timezone of `/me`, for the dates said in a command. */
    private val currentZone: () -> ZoneId,
    private val service: VoiceService,
    private val interpreter: VoiceInterpreter,
    private val preferences: InterpreterPreferenceStore,
    private val permissions: VoicePermissions,
    val speech: SpeechInput,
    private val speaker: VoiceSpeaker,
    private val strings: VoiceStrings,
    private val clock: Clock = Clock.systemUTC(),
) : SessionListener {
    private val handler = VoiceResponseHandler(service)

    private val mutablePhase = MutableStateFlow<VoicePhase>(VoicePhase.Idle)
    val phase: StateFlow<VoicePhase> = mutablePhase.asStateFlow()

    private val mutableIsPresented = MutableStateFlow(false)
    val isPresented: StateFlow<Boolean> = mutableIsPresented.asStateFlow()

    private val mutableIsSending = MutableStateFlow(false)

    /** A pick or a confirmation is on its way. */
    val isSending: StateFlow<Boolean> = mutableIsSending.asStateFlow()

    /** The answer the options of a clarification belong to. */
    private var lastResult: VoiceCommandResult? = null
    private var work: Job? = null

    private val language: AppLanguage
        get() = currentLanguage()

    /** Where the commands are interpreted (Settings → Voice). */
    val preference: StateFlow<InterpreterPreference>
        get() = preferences.preference

    fun updatePreference(preference: InterpreterPreference) = preferences.save(preference)

    /** Whether this phone can interpret on the device in the user's language. */
    suspend fun isLocalAvailable(): Boolean = interpreter.isLocalAvailable(language)

    /**
     * The microphone button: opens the sheet and listens, after the permission.
     *
     * @param shouldShowRationale the activity's `shouldShowRequestPermissionRationale(RECORD_AUDIO)`.
     */
    fun begin(shouldShowRationale: Boolean) {
        mutableIsPresented.value = true
        listen(shouldShowRationale)
    }

    /**
     * Runs an App Action's action (`POST /voice/actions`) in the sheet. The
     * app may have just been launched by the Assistant: it waits for the session.
     */
    fun run(action: VoiceAction) {
        reset()
        mutableIsPresented.value = true
        mutablePhase.value = VoicePhase.Interpreting(null)
        work = scope.launch {
            if (!awaitSignedIn()) {
                fail(string(R.string.voice_sign_in_first))

                return@launch
            }

            answer(null) { service.run(action) }
        }
    }

    /** Listens again from the sheet. */
    fun listen(shouldShowRationale: Boolean) {
        reset()
        apply(permissions.step(shouldShowRationale))
    }

    /** The sheet asks the prompt again (a refusal Android still lets the app ask about). */
    fun askPermission() {
        mutablePhase.value = VoicePhase.RequestingPermission
    }

    /** The system prompt is on screen. */
    fun didShowPermissionPrompt() = permissions.didAsk()

    /** The answer of the system prompt. */
    fun didAnswerPermission(granted: Boolean, shouldShowRationale: Boolean) =
        apply(permissions.didAnswer(granted, shouldShowRationale))

    /** The user tapped to stop: the final text comes from the recognizer. */
    fun stopListening() = speech.finish()

    /** The user picked an option of a clarification. */
    fun choose(option: VoiceOption) {
        val answered = mutablePhase.value as? VoicePhase.Answered ?: return
        val result = lastResult ?: return

        if (answered.flow is VoiceResponseFlow.Clarify) {
            send(answered.text) { handler.choose(option, result) }
        }
    }

    /** The user tapped Send: only now is the WhatsApp message sent. */
    fun confirm() {
        val answered = mutablePhase.value as? VoicePhase.Answered ?: return
        val flow = answered.flow as? VoiceResponseFlow.Confirm ?: return

        send(answered.text) { handler.confirm(flow.confirmation) }
    }

    /** The user did not confirm or pick: nothing runs. */
    fun cancelAnswer() {
        val answered = mutablePhase.value as? VoicePhase.Answered ?: return

        lastResult = null
        show(answered.text, VoiceResponseFlow.Failed(string(R.string.voice_cancelled)))
    }

    /** The sheet was closed. */
    fun dismiss() {
        reset()
        mutablePhase.value = VoicePhase.Idle
        mutableIsPresented.value = false
    }

    override suspend fun onSessionEnded() = dismiss()

    private fun apply(step: MicrophoneStep) {
        when (step) {
            MicrophoneStep.Listen -> startListening()
            MicrophoneStep.Ask -> mutablePhase.value = VoicePhase.RequestingPermission
            is MicrophoneStep.Explain -> mutablePhase.value = VoicePhase.AccessDenied(step.canAskAgain)
        }
    }

    private fun startListening() {
        val isStarted = speech.start(language) { outcome -> didHear(outcome) }

        if (isStarted) {
            mutablePhase.value = VoicePhase.Listening
        } else {
            fail(string(R.string.voice_recognizer_unavailable))
        }
    }

    private fun didHear(outcome: SpeechOutcome) {
        when (outcome) {
            SpeechOutcome.PermissionDenied -> mutablePhase.value = VoicePhase.AccessDenied(canAskAgain = false)
            SpeechOutcome.Unavailable -> fail(string(R.string.voice_recognizer_unavailable))
            is SpeechOutcome.Heard -> if (outcome.text.isEmpty()) {
                fail(string(R.string.voice_not_heard))
            } else {
                interpret(outcome.text)
            }
        }
    }

    private fun interpret(text: String) {
        mutablePhase.value = VoicePhase.Interpreting(text)
        work = scope.launch {
            val zone = currentZone()

            try {
                val result = interpreter.interpret(text, preferences.preference.value, language, LocalDateTime.now(clock.withZone(zone)), zone)
                lastResult = result
                show(text, handler.flow(result))
            } catch (exception: VoiceInterpreterException) {
                fail(message(exception))
            }
        }
    }

    /** Runs a call whose answer is shown, or its failure. */
    private suspend fun answer(text: String?, call: suspend () -> VoiceCommandResult) {
        try {
            val result = call()
            lastResult = result
            show(text, handler.flow(result))
        } catch (exception: ApiException) {
            fail(message(exception))
        }
    }

    private fun send(text: String?, call: suspend () -> VoiceCommandResult) {
        if (mutableIsSending.value) {
            return
        }

        mutableIsSending.value = true
        work = scope.launch {
            try {
                answer(text, call)
            } finally {
                mutableIsSending.value = false
            }
        }
    }

    private fun reset() {
        work?.cancel(CancellationException("The voice command was replaced"))
        work = null
        speech.cancel()
        speaker.stop()
        lastResult = null
        mutableIsSending.value = false
    }

    /** Shows the answer and speaks it. */
    private fun show(text: String?, flow: VoiceResponseFlow) {
        mutablePhase.value = VoicePhase.Answered(text, flow)
        speaker.speak(flow.message, language)
    }

    private fun fail(message: String) {
        mutablePhase.value = VoicePhase.Failed(message)
        speaker.speak(message, language)
    }

    private fun message(exception: VoiceInterpreterException): String = when (exception) {
        is VoiceInterpreterException.LocalUnavailable -> string(R.string.voice_local_unavailable)
        is VoiceInterpreterException.NotUnderstood -> string(R.string.voice_not_understood)
        is VoiceInterpreterException.LocalFailed -> string(R.string.voice_local_failed)
        is VoiceInterpreterException.Api -> message(exception.exception)
    }

    private fun message(exception: ApiException): String = when (exception) {
        is ApiException.Transport -> string(R.string.error_unreachable)
        is ApiException.Unauthenticated -> string(R.string.voice_sign_in_first)
        else -> exception.displayMessage ?: string(R.string.error_generic)
    }

    private fun string(id: Int): String = strings.get(id, language)
}
