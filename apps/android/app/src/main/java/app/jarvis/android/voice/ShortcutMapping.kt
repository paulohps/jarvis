package app.jarvis.android.voice

import app.jarvis.android.network.VoiceAction
import app.jarvis.android.network.VoiceActionName
import app.jarvis.android.ui.schedule.WallClock
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.LocalTime

/**
 * The App Actions of Jarvis (`res/xml/shortcuts.xml`, US-10.1, US-10.3).
 * Each capability opens `MainActivity` with its own intent action and the
 * parameters the Assistant understood as extras, keyed by [keys].
 *
 * The shopping list and the message are built-in intents; Android has none
 * for reminders, so these are custom intents (`custom.actions.intent.*`),
 * which the Assistant only understands in en-US.
 */
enum class AppActionCapability(val capabilityName: String, val intentAction: String, val keys: Set<String>) {
    /** "Add milk to my weekly list in Jarvis". */
    AddShoppingItem("actions.intent.UPDATE_ITEM_LIST", "app.jarvis.android.action.ADD_SHOPPING_ITEM", setOf("item", "list")),

    /** "Remind me to pay the rent tomorrow at 9 in Jarvis". */
    CreateReminder("custom.actions.intent.CREATE_REMINDER", "app.jarvis.android.action.CREATE_REMINDER", setOf("title", "date", "time")),

    /** "Complete the reminder pay the rent in Jarvis". */
    CompleteReminder("custom.actions.intent.COMPLETE_REMINDER", "app.jarvis.android.action.COMPLETE_REMINDER", setOf("reminder")),

    /** "Send a message to Ana saying I am on my way with Jarvis": always confirmed in the app. */
    SendWhatsappMessage("actions.intent.CREATE_MESSAGE", "app.jarvis.android.action.SEND_WHATSAPP_MESSAGE", setOf("target", "body")),
    ;

    companion object {
        fun forIntentAction(action: String?): AppActionCapability? = entries.firstOrNull { it.intentAction == action }
    }
}

/**
 * Turns an App Action into the [VoiceAction] `POST /voice/actions` runs,
 * the same as the microphone's: the server resolves the names (fuzzy), asks
 * which one on an ambiguity and asks to confirm every WhatsApp send.
 */
object ShortcutMapping {
    /** List names that only say "the shopping list": the server picks the list (the only one, or asks which). */
    private val genericListNames = setOf(
        "shopping", "shopping list", "my shopping list", "the shopping list", "grocery", "groceries", "grocery list", "my grocery list",
        "compras", "lista de compras", "minha lista de compras", "a lista de compras", "lista do mercado", "mercado",
    )

    /**
     * @param extras the intent's extras, by the parameter keys of `shortcuts.xml`.
     * @param now the user's wall clock, for a reminder said with a time only.
     * @return `null` when a required value is missing (the app opens the microphone instead).
     */
    fun voiceAction(capability: AppActionCapability, extras: Map<String, String?>, now: LocalDateTime): VoiceAction? {
        val action = when (capability) {
            AppActionCapability.AddShoppingItem -> VoiceAction.of(
                VoiceActionName.ShoppingAddItem,
                mapOf("item" to extras["item"], "list" to extras["list"]?.takeUnless { it.trim().lowercase() in genericListNames }),
            )

            AppActionCapability.CreateReminder -> VoiceAction.of(
                VoiceActionName.RemindersCreate,
                mapOf("title" to extras["title"], "due_at" to dueAt(extras["date"], extras["time"], now)?.let(WallClock::dateTime)),
            )

            AppActionCapability.CompleteReminder -> VoiceAction.of(VoiceActionName.RemindersComplete, mapOf("reminder" to extras["reminder"]))

            AppActionCapability.SendWhatsappMessage -> VoiceAction.of(
                VoiceActionName.WhatsappSend,
                mapOf("target" to extras["target"], "body" to extras["body"]),
            )
        }

        return action.takeIf { action.parameters.keys.containsAll(required.getValue(action.name)) }
    }

    /** The parameters an action cannot run without (`VoiceActionInput`). */
    val required: Map<VoiceActionName, Set<String>> = mapOf(
        VoiceActionName.ShoppingAddItem to setOf("item"),
        VoiceActionName.ShoppingCheckItem to setOf("item"),
        VoiceActionName.RemindersCreate to setOf("title"),
        VoiceActionName.RemindersComplete to setOf("reminder"),
        VoiceActionName.WhatsappSend to setOf("target", "body"),
    )

    /**
     * The due date on the wall clock: a date and a time; a date alone at
     * 09:00; a time alone today, or tomorrow when it already passed.
     */
    private fun dueAt(date: String?, time: String?, now: LocalDateTime): LocalDateTime? {
        val day = date?.let { runCatching { LocalDate.parse(it.trim().take(10)) }.getOrNull() }
        val clock = time?.let { runCatching { LocalTime.parse(it.trim()) }.getOrNull() }

        return when {
            day != null && clock != null -> day.atTime(clock)
            day != null -> day.atTime(DEFAULT_TIME)
            clock != null -> now.toLocalDate().atTime(clock).let { if (it.isAfter(now)) it else it.plusDays(1) }
            else -> null
        }
    }

    private val DEFAULT_TIME: LocalTime = LocalTime.of(9, 0)
}
