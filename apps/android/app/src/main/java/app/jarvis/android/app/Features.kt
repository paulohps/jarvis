package app.jarvis.android.app

/** Parts of the app switched off while they are reworked; the code stays. */
object Features {
    /**
     * "Ask Jarvis" (US-10.1): the microphone button of the tabs and its
     * settings. Hidden until the voice commands are redone; the App Actions
     * of the Assistant (`res/xml/shortcuts.xml`) still open the sheet.
     */
    const val ASK_JARVIS = false

    /**
     * The scheduled messages and the history panes of the WhatsApp tab, and
     * its "New" menu (scheduling, sending now). Hidden: the tab is the chat
     * only, and a chat schedules its own messages.
     */
    const val WHATSAPP_SCHEDULED_PANES = false
}
