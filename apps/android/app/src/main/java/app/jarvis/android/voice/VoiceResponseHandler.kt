package app.jarvis.android.voice

import app.jarvis.android.network.VoiceClarification
import app.jarvis.android.network.VoiceCommandResult
import app.jarvis.android.network.VoiceConfirmation
import app.jarvis.android.network.VoiceOption
import app.jarvis.android.network.VoiceStatus

/** What the app does with the answer of a voice command (US-10.3); [message] comes translated. */
sealed interface VoiceResponseFlow {
    val message: String

    /** The action ran: show and speak the message. */
    data class Done(override val message: String) : VoiceResponseFlow

    /** Nothing ran yet: show what would be sent and wait for a tap. */
    data class Confirm(override val message: String, val confirmation: VoiceConfirmation) : VoiceResponseFlow

    /** More than one candidate: show the options; the picked one runs the action again. */
    data class Clarify(override val message: String, val clarification: VoiceClarification) : VoiceResponseFlow

    /** The action's module is switched off for the user. */
    data class Refused(override val message: String) : VoiceResponseFlow

    /** Not understood, nothing found, or refused by the validation. */
    data class Failed(override val message: String) : VoiceResponseFlow
}

/**
 * Leads a voice answer to its flow and carries it on: a clarification goes
 * back to `POST /voice/actions` with the picked option, a confirmation to
 * `POST /voice/commands/confirm`, and never without the user's tap.
 */
class VoiceResponseHandler(private val service: VoiceService) {
    /** The flow of an answer; an answer missing what its status needs fails. */
    fun flow(result: VoiceCommandResult): VoiceResponseFlow = when (result.status) {
        VoiceStatus.Done -> VoiceResponseFlow.Done(result.message)
        VoiceStatus.NeedsConfirmation -> result.confirmation
            ?.let { VoiceResponseFlow.Confirm(result.message, it) }
            ?: VoiceResponseFlow.Failed(result.message)

        VoiceStatus.NeedsClarification -> result.clarification
            ?.takeIf { result.action != null && it.options.isNotEmpty() }
            ?.let { VoiceResponseFlow.Clarify(result.message, it) }
            ?: VoiceResponseFlow.Failed(result.message)

        VoiceStatus.Forbidden -> VoiceResponseFlow.Refused(result.message)
        VoiceStatus.Failed -> VoiceResponseFlow.Failed(result.message)
    }

    /** The user picked an option of a clarification; returns the new answer. */
    suspend fun choose(option: VoiceOption, after: VoiceCommandResult): VoiceCommandResult = service.choose(option, after)

    /** The user tapped Send on the confirmation; returns the new answer. */
    suspend fun confirm(confirmation: VoiceConfirmation): VoiceCommandResult = service.confirm(confirmation)
}
