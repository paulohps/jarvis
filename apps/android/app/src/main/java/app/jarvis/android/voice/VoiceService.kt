package app.jarvis.android.voice

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.VoiceAction
import app.jarvis.android.network.VoiceCommandRequest
import app.jarvis.android.network.VoiceCommandResult
import app.jarvis.android.network.VoiceConfirmRequest
import app.jarvis.android.network.VoiceConfirmation
import app.jarvis.android.network.VoiceOption

/**
 * The voice endpoints of the API v1, shared by the App Actions and the
 * microphone button: the same actions on the server, never a parallel logic.
 * Every call throws [app.jarvis.android.network.ApiException].
 */
class VoiceService(private val client: ApiClient) {
    /** Runs an action structured on the phone (`POST /voice/actions`). */
    suspend fun run(action: VoiceAction): VoiceCommandResult = client.call { runVoiceAction(action) }

    /** Sends the spoken text to the server's interpreter (`POST /voice/commands`). */
    suspend fun interpret(text: String): VoiceCommandResult = client.call { interpretVoiceCommand(VoiceCommandRequest(text)) }

    /**
     * Answers a `needs_clarification` with the option the user picked: the
     * same action and parameters, with the option's id as the parameter asked.
     */
    suspend fun choose(option: VoiceOption, result: VoiceCommandResult): VoiceCommandResult {
        val action = result.actionChoosing(option) ?: return result

        return run(action)
    }

    /** Sends the WhatsApp message the user confirmed (`POST /voice/commands/confirm`). */
    suspend fun confirm(confirmation: VoiceConfirmation): VoiceCommandResult =
        client.call { confirmVoiceCommand(VoiceConfirmRequest(confirmation.token)) }
}
