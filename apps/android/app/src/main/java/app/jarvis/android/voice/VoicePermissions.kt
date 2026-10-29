package app.jarvis.android.voice

/** The microphone permission (`RECORD_AUDIO`) as the app last saw it. */
interface MicrophonePermission {
    fun isGranted(): Boolean

    /** Whether the system prompt was ever shown: Android does not tell "never asked" from "refused for good". */
    fun wasAsked(): Boolean

    fun markAsked()
}

/** What the microphone button does before listening. */
sealed interface MicrophoneStep {
    /** Listen. */
    data object Listen : MicrophoneStep

    /** Show the system prompt. */
    data object Ask : MicrophoneStep

    /**
     * Explain why the microphone is needed. With [canAskAgain] the app can
     * show the prompt again; without it only the Android settings can turn it on.
     */
    data class Explain(val canAskAgain: Boolean) : MicrophoneStep
}

/**
 * The `RECORD_AUDIO` flow of the microphone button (US-10.1). The prompt is
 * shown while it was never answered; a refusal is never a crash: the app
 * explains, and offers the prompt again while Android still shows it
 * (`shouldShowRequestPermissionRationale`), otherwise the Android settings.
 */
class VoicePermissions(private val permission: MicrophonePermission) {
    /**
     * Before listening.
     *
     * @param shouldShowRationale the activity's `shouldShowRequestPermissionRationale(RECORD_AUDIO)`.
     */
    fun step(shouldShowRationale: Boolean): MicrophoneStep = when {
        permission.isGranted() -> MicrophoneStep.Listen
        !permission.wasAsked() -> MicrophoneStep.Ask
        else -> MicrophoneStep.Explain(canAskAgain = shouldShowRationale)
    }

    /** The prompt is on screen. */
    fun didAsk() = permission.markAsked()

    /** The answer of the prompt. */
    fun didAnswer(granted: Boolean, shouldShowRationale: Boolean): MicrophoneStep =
        if (granted) MicrophoneStep.Listen else MicrophoneStep.Explain(canAskAgain = shouldShowRationale)
}
