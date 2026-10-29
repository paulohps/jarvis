package app.jarvis.android.voice

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer
import androidx.core.content.edit
import app.jarvis.android.i18n.AppLanguage
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/** How a listening ended. */
sealed interface SpeechOutcome {
    /** The final text; empty when nothing was understood. */
    data class Heard(val text: String) : SpeechOutcome

    /** No recognizer on the phone, or it failed (the network of a server one). */
    data object Unavailable : SpeechOutcome

    /** The microphone permission was taken away while listening. */
    data object PermissionDenied : SpeechOutcome
}

/** Live speech-to-text in the user's language (US-10.1). */
interface SpeechInput {
    /** What was understood so far, while the user speaks. */
    val transcript: StateFlow<String>

    val isListening: StateFlow<Boolean>

    /** Starts listening; [onFinish] gets the outcome once. `false` when there is no recognizer. */
    fun start(language: AppLanguage, onFinish: (SpeechOutcome) -> Unit): Boolean

    /** The user is done: the recognizer gives its final text. */
    fun finish()

    /** Stops without an outcome (the sheet was closed). */
    fun cancel()
}

/** `RECORD_AUDIO`; whether the app already asked is kept, as Android does not tell. */
class AndroidMicrophonePermission(context: Context) : MicrophonePermission {
    private val appContext = context.applicationContext
    private val preferences = appContext.getSharedPreferences("voice", Context.MODE_PRIVATE)

    override fun isGranted(): Boolean =
        appContext.checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED

    override fun wasAsked(): Boolean = preferences.getBoolean(KEY_ASKED, false)

    override fun markAsked() = preferences.edit { putBoolean(KEY_ASKED, true) }

    private companion object {
        const val KEY_ASKED = "microphone_asked"
    }
}

/**
 * The system's `SpeechRecognizer` (the default recognition service, or the
 * on-device one when there is no other), with partial results. The
 * recognizer ends the listening itself after a silence. It runs on the main thread.
 */
class AndroidSpeechInput(context: Context) : SpeechInput {
    private val appContext = context.applicationContext
    private var recognizer: SpeechRecognizer? = null
    private var onFinish: ((SpeechOutcome) -> Unit)? = null

    private val mutableTranscript = MutableStateFlow("")
    override val transcript: StateFlow<String> = mutableTranscript.asStateFlow()

    private val mutableIsListening = MutableStateFlow(false)
    override val isListening: StateFlow<Boolean> = mutableIsListening.asStateFlow()

    override fun start(language: AppLanguage, onFinish: (SpeechOutcome) -> Unit): Boolean {
        cancel()

        val recognizer = when {
            SpeechRecognizer.isRecognitionAvailable(appContext) -> SpeechRecognizer.createSpeechRecognizer(appContext)
            SpeechRecognizer.isOnDeviceRecognitionAvailable(appContext) -> SpeechRecognizer.createOnDeviceSpeechRecognizer(appContext)
            else -> return false
        }

        this.recognizer = recognizer
        this.onFinish = onFinish
        mutableTranscript.value = ""
        recognizer.setRecognitionListener(Listener())
        recognizer.startListening(
            Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH)
                .putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
                .putExtra(RecognizerIntent.EXTRA_LANGUAGE, language.languageTag)
                .putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, true)
                .putExtra(RecognizerIntent.EXTRA_CALLING_PACKAGE, appContext.packageName),
        )
        mutableIsListening.value = true

        return true
    }

    override fun finish() {
        recognizer?.stopListening()
    }

    override fun cancel() {
        onFinish = null
        recognizer?.destroy()
        recognizer = null
        mutableIsListening.value = false
    }

    private fun end(outcome: SpeechOutcome) {
        val finish = onFinish
        onFinish = null
        recognizer?.destroy()
        recognizer = null
        mutableIsListening.value = false
        finish?.invoke(outcome)
    }

    private fun Bundle?.bestText(): String? =
        this?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)?.firstOrNull()?.takeIf { it.isNotBlank() }

    private inner class Listener : RecognitionListener {
        override fun onPartialResults(partialResults: Bundle?) {
            partialResults.bestText()?.let { mutableTranscript.value = it }
        }

        override fun onResults(results: Bundle?) {
            results.bestText()?.let { mutableTranscript.value = it }
            end(SpeechOutcome.Heard(mutableTranscript.value.trim()))
        }

        override fun onError(error: Int) {
            when (error) {
                // Silence or nothing recognizable: what was heard so far, maybe nothing.
                SpeechRecognizer.ERROR_NO_MATCH, SpeechRecognizer.ERROR_SPEECH_TIMEOUT -> end(SpeechOutcome.Heard(mutableTranscript.value.trim()))
                SpeechRecognizer.ERROR_INSUFFICIENT_PERMISSIONS -> end(SpeechOutcome.PermissionDenied)
                else -> end(SpeechOutcome.Unavailable)
            }
        }

        override fun onReadyForSpeech(params: Bundle?) = Unit

        override fun onBeginningOfSpeech() = Unit

        override fun onRmsChanged(rmsdB: Float) = Unit

        override fun onBufferReceived(buffer: ByteArray?) = Unit

        override fun onEndOfSpeech() = Unit

        override fun onEvent(eventType: Int, params: Bundle?) = Unit
    }
}
