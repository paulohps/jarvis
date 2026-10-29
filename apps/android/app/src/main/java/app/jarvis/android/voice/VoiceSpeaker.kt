package app.jarvis.android.voice

import android.content.Context
import android.media.AudioAttributes
import android.speech.tts.TextToSpeech
import app.jarvis.android.i18n.AppLanguage

/** Speaks the answer of a voice command (US-10.3). */
interface VoiceSpeaker {
    fun speak(text: String, language: AppLanguage)

    fun stop()
}

/**
 * The system's `TextToSpeech`, in the user's language, as the assistant's
 * voice. The engine starts on the first answer; what comes before it is
 * ready is spoken once it is.
 */
class AndroidVoiceSpeaker(context: Context) : VoiceSpeaker {
    private val appContext = context.applicationContext
    private var engine: TextToSpeech? = null
    private var isReady = false
    private var pending: Pair<String, AppLanguage>? = null

    override fun speak(text: String, language: AppLanguage) {
        if (text.isBlank()) {
            return
        }

        val engine = engine ?: start()

        if (isReady) {
            utter(engine, text, language)
        } else {
            pending = text to language
        }
    }

    override fun stop() {
        pending = null
        engine?.stop()
    }

    private fun start(): TextToSpeech {
        lateinit var created: TextToSpeech
        created = TextToSpeech(appContext) { status ->
            if (status != TextToSpeech.SUCCESS) {
                // No engine on the phone: the answer stays on the screen only.
                pending = null
                engine = null
                created.shutdown()

                return@TextToSpeech
            }

            isReady = true
            created.setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ASSISTANT)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                    .build(),
            )
            pending?.let { (text, language) -> utter(created, text, language) }
            pending = null
        }
        engine = created

        return created
    }

    private fun utter(engine: TextToSpeech, text: String, language: AppLanguage) {
        engine.setLanguage(language.locale)
        engine.speak(text, TextToSpeech.QUEUE_FLUSH, null, UTTERANCE_ID)
    }

    private companion object {
        const val UTTERANCE_ID = "jarvis.voice.answer"
    }
}
