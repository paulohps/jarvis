package app.jarvis.android.voice

import app.jarvis.android.R
import app.jarvis.android.i18n.AppLanguage
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.TestScope
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class VoicePermissionsTest {
    private val permission = FakeMicrophonePermission()
    private val permissions = VoicePermissions(permission)
    private val speech = FakeSpeechInput()

    private fun controller() = voiceController(
        scope = TestScope(StandardTestDispatcher()),
        language = AppLanguage.BrazilianPortuguese,
        permission = permission,
        speech = speech,
    )

    @Test
    fun `a granted microphone listens`() {
        permission.granted = true

        assertEquals(MicrophoneStep.Listen, permissions.step(shouldShowRationale = false))
    }

    @Test
    fun `the prompt is shown while it was never answered`() {
        assertEquals(MicrophoneStep.Ask, permissions.step(shouldShowRationale = false))

        permissions.didAsk()

        assertTrue(permission.asked)
    }

    @Test
    fun `a refusal is explained, with the prompt again while Android still shows it`() {
        permission.asked = true

        assertEquals(MicrophoneStep.Explain(canAskAgain = true), permissions.step(shouldShowRationale = true))
        assertEquals(MicrophoneStep.Explain(canAskAgain = false), permissions.step(shouldShowRationale = false))
    }

    @Test
    fun `the answer of the prompt listens or explains`() {
        assertEquals(MicrophoneStep.Listen, permissions.didAnswer(granted = true, shouldShowRationale = false))
        assertEquals(MicrophoneStep.Explain(canAskAgain = true), permissions.didAnswer(granted = false, shouldShowRationale = true))
        assertEquals(MicrophoneStep.Explain(canAskAgain = false), permissions.didAnswer(granted = false, shouldShowRationale = false))
    }

    @Test
    fun `denying the permission shows the explanation and never listens`() {
        val voice = controller()

        voice.begin(shouldShowRationale = false)
        assertEquals(VoicePhase.RequestingPermission, voice.phase.value)
        assertTrue(voice.isPresented.value)

        voice.didShowPermissionPrompt()
        voice.didAnswerPermission(granted = false, shouldShowRationale = true)
        assertEquals(VoicePhase.AccessDenied(canAskAgain = true), voice.phase.value)

        // Refused for good: the next tap explains how to turn it on in the Android settings.
        voice.listen(shouldShowRationale = false)
        assertEquals(VoicePhase.AccessDenied(canAskAgain = false), voice.phase.value)
        assertEquals(null, speech.startedLanguage)
    }

    @Test
    fun `allowing listens in the users language`() {
        val voice = controller()

        voice.begin(shouldShowRationale = false)
        voice.didAnswerPermission(granted = true, shouldShowRationale = false)

        assertEquals(VoicePhase.Listening, voice.phase.value)
        assertEquals(AppLanguage.BrazilianPortuguese, speech.startedLanguage)
    }

    @Test
    fun `nothing heard or no recognizer is a message, not a crash`() {
        permission.granted = true
        val voice = controller()

        voice.begin(shouldShowRationale = false)
        speech.hear(SpeechOutcome.Heard(""))
        assertEquals(VoicePhase.Failed("${R.string.voice_not_heard}/pt-BR"), voice.phase.value)

        speech.isAvailable = false
        voice.listen(shouldShowRationale = false)
        assertEquals(VoicePhase.Failed("${R.string.voice_recognizer_unavailable}/pt-BR"), voice.phase.value)
    }

    @Test
    fun `the permission taken away while listening is explained`() {
        permission.granted = true
        val voice = controller()

        voice.begin(shouldShowRationale = false)
        speech.hear(SpeechOutcome.PermissionDenied)

        assertEquals(VoicePhase.AccessDenied(canAskAgain = false), voice.phase.value)
        assertFalse(speech.isListening.value)
    }
}
