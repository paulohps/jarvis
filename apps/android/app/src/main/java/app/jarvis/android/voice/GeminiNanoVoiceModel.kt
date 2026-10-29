package app.jarvis.android.voice

import app.jarvis.android.i18n.AppLanguage
import app.jarvis.android.network.VoiceAction
import app.jarvis.android.network.VoiceActionName
import app.jarvis.android.ui.schedule.WallClock
import com.google.mlkit.genai.common.FeatureStatus
import com.google.mlkit.genai.prompt.Generation
import com.google.mlkit.genai.prompt.GenerativeModel
import com.google.mlkit.genai.prompt.TextPart
import com.google.mlkit.genai.prompt.generateContentRequest
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import java.time.LocalDateTime
import java.time.ZoneId

/**
 * The local interpreter (US-10.2): Gemini Nano, through the ML Kit GenAI
 * Prompt API (AICore), turns the spoken text into the same [VoiceAction]
 * the server's interpreter produces, so the text never leaves the phone. It
 * runs only where Gemini Nano is downloaded and ready and the language is
 * one the Prompt API was verified for (English, for now: a Portuguese
 * command goes to the server in automatic mode).
 */
class GeminiNanoVoiceModel(
    private val supportedLanguages: Set<AppLanguage> = setOf(AppLanguage.English),
) : OnDeviceVoiceModel {
    private val model: GenerativeModel by lazy { Generation.getClient() }

    override suspend fun isAvailable(language: AppLanguage): Boolean =
        language in supportedLanguages && model.checkStatus() == FeatureStatus.AVAILABLE

    override suspend fun action(text: String, language: AppLanguage, now: LocalDateTime, zone: ZoneId): VoiceAction? {
        val response = model.generateContent(
            generateContentRequest(TextPart(SpokenCommand.prompt(text, now, zone))) {
                temperature = 0f
                maxOutputTokens = 256
            },
        )

        return SpokenCommand.parse(response.candidates.firstOrNull()?.text.orEmpty())
    }
}

/** What the on-device model answers for a spoken command, as JSON. */
@Serializable
data class SpokenCommand(
    val kind: String = "none",
    val item: String? = null,
    val quantity: String? = null,
    val list: String? = null,
    val title: String? = null,
    val dueAt: String? = null,
    val note: String? = null,
    val reminder: String? = null,
    val target: String? = null,
    val body: String? = null,
    val account: String? = null,
) {
    /** The action for `POST /voice/actions`; `null` when no action fits or a required value is missing. */
    val voiceAction: VoiceAction?
        get() {
            val action = when (kind) {
                "addShoppingItem" -> VoiceAction.of(VoiceActionName.ShoppingAddItem, mapOf("item" to item, "quantity" to quantity, "list" to list))
                "checkShoppingItem" -> VoiceAction.of(VoiceActionName.ShoppingCheckItem, mapOf("item" to item, "list" to list))
                "createReminder" -> VoiceAction.of(
                    VoiceActionName.RemindersCreate,
                    mapOf("title" to title, "due_at" to dueAt?.takeIf { WALL_CLOCK.matches(it.trim()) }, "note" to note),
                )

                "completeReminder" -> VoiceAction.of(VoiceActionName.RemindersComplete, mapOf("reminder" to reminder))
                "sendWhatsappMessage" -> VoiceAction.of(VoiceActionName.WhatsappSend, mapOf("target" to target, "body" to body, "account" to account))
                else -> return null
            }

            return action.takeIf { action.parameters.keys.containsAll(ShortcutMapping.required.getValue(action.name)) }
        }

    companion object {
        private val WALL_CLOCK = Regex("""\d{4}-\d{2}-\d{2}T\d{2}:\d{2}""")
        private val json = Json { ignoreUnknownKeys = true; isLenient = true; coerceInputValues = true }

        /**
         * The prompt: the actions, today's date (so "amanhã às 9h" becomes a
         * wall clock date) and the command. Names are kept as spoken.
         */
        fun prompt(text: String, now: LocalDateTime, zone: ZoneId): String = """
            You turn a spoken command of a family assistant into one action. The command may be in English or Brazilian Portuguese.
            Keep names as spoken (a list "dessa semana", a contact "Ana"); never translate them.
            Actions (the "kind"):
            - addShoppingItem: add "item" (optional "quantity") to a shopping "list" (leave it out if no list is named; never "shopping list" or "lista de compras").
            - checkShoppingItem: check off "item" in a shopping "list".
            - createReminder: a reminder "title", optional "dueAt" and "note".
            - completeReminder: mark the "reminder" named as done.
            - sendWhatsappMessage: send "body" to the contact or group "target", optional "account".
            - none: the command asks for none of these.
            Now is ${WallClock.dateTime(now)} (${zone.id}). Write "dueAt" as YYYY-MM-DDTHH:MM on that clock.
            Answer with only one JSON object, e.g. {"kind":"addShoppingItem","item":"leite","list":"dessa semana"}.
            Command: $text
        """.trimIndent()

        /** The action of the model's answer: the first JSON object in it, code fences and prose aside. */
        fun parse(answer: String): VoiceAction? {
            val start = answer.indexOf('{')
            val end = answer.lastIndexOf('}')

            if (start < 0 || end <= start) {
                return null
            }

            return runCatching { json.decodeFromString<SpokenCommand>(answer.substring(start, end + 1)) }.getOrNull()?.voiceAction
        }
    }
}
