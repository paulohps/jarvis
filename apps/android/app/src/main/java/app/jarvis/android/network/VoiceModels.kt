package app.jarvis.android.network

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** The actions a voice command can run (`components/schemas/VoiceActionInput`). */
@Serializable
enum class VoiceActionName {
    @SerialName("shopping.add_item") ShoppingAddItem,
    @SerialName("shopping.check_item") ShoppingCheckItem,
    @SerialName("reminders.create") RemindersCreate,
    @SerialName("reminders.complete") RemindersComplete,
    @SerialName("whatsapp.send") WhatsappSend,
}

/**
 * The structured action of a voice command (US-10.2, US-10.3): what both
 * interpreters produce, the on-device one and the server's, and what
 * `POST /voice/actions` runs. The parameters are as spoken (`dessa semana`)
 * or the friendly id of an option the user picked after a clarification;
 * the keys are the API's (`item`, `due_at`, `target`…), and a parameter left
 * out is `null` on the server. Build it with [of], which leaves out blank values.
 */
@Serializable
data class VoiceAction(
    @SerialName("action") val name: VoiceActionName,
    val parameters: Map<String, String> = emptyMap(),
) {
    /** The same action with a parameter replaced, e.g. by the picked option's id. */
    fun with(parameter: String, value: String?): VoiceAction = of(name, parameters + (parameter to value))

    companion object {
        /** An action whose blank parameters are left out, the others trimmed. */
        fun of(name: VoiceActionName, parameters: Map<String, String?>): VoiceAction = VoiceAction(
            name,
            parameters.mapNotNull { (key, value) -> value?.trim()?.takeIf { it.isNotEmpty() }?.let { key to it } }.toMap(),
        )
    }
}

/** `VoiceCommandResult.status`. */
@Serializable
enum class VoiceStatus {
    /** The action ran. */
    @SerialName("done") Done,

    /** Nothing ran: confirm with `confirmation.token` (every `whatsapp.send`). */
    @SerialName("needs_confirmation") NeedsConfirmation,

    /** More than one candidate: send the action again with the picked option. */
    @SerialName("needs_clarification") NeedsClarification,

    /** The action's module is switched off for the user. */
    @SerialName("forbidden") Forbidden,

    /** Not understood, nothing found, or refused by the action's validation. */
    @SerialName("failed") Failed,
}

/** An option of a `needs_clarification` answer; [id] is a friendly id. */
@Serializable
data class VoiceOption(val id: String, val label: String)

/** Which parameter the user has to pick (`list`, `item`, `reminder` or `target`), and the candidates. */
@Serializable
data class VoiceClarification(val parameter: String, val options: List<VoiceOption> = emptyList())

/** What a voice WhatsApp send would send, for the user to confirm. */
@Serializable
data class VoiceConfirmationSummary(
    val account: String = "",
    val target: String = "",
    @SerialName("target_type") val targetType: WhatsappTargetType? = null,
    val body: String = "",
)

/** The token of a `needs_confirmation` answer: it works once, for 5 minutes. */
@Serializable
data class VoiceConfirmation(
    val token: String,
    @SerialName("expires_at") val expiresAt: String? = null,
    val summary: VoiceConfirmationSummary = VoiceConfirmationSummary(),
)

/**
 * `components/schemas/VoiceCommandResult`: what a command came to. The
 * `message` comes translated, for the screen and the speech.
 */
@Serializable
data class VoiceCommandResult(
    /** `null` when the command was not understood. */
    val action: VoiceActionName? = null,
    val status: VoiceStatus,
    val message: String = "",
    /** The action's parameters as understood, keyed as the API names them. */
    @SerialName("parameters") private val rawParameters: Map<String, String?>? = null,
    val clarification: VoiceClarification? = null,
    val confirmation: VoiceConfirmation? = null,
) {
    /** The parameters with a value. */
    val parameters: Map<String, String>
        get() = rawParameters.orEmpty().mapNotNull { (key, value) -> value?.let { key to it } }.toMap()

    /** The action to send again once the user picked an option of the clarification. */
    fun actionChoosing(option: VoiceOption): VoiceAction? {
        val action = action ?: return null
        val clarification = clarification ?: return null

        return VoiceAction.of(action, parameters).with(clarification.parameter, option.id)
    }
}

/** `POST /voice/commands`. */
@Serializable
data class VoiceCommandRequest(val text: String)

/** `POST /voice/commands/confirm`. */
@Serializable
data class VoiceConfirmRequest(@SerialName("confirmation_token") val confirmationToken: String)
