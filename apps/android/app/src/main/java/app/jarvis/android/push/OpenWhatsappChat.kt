package app.jarvis.android.push

/**
 * The WhatsApp chat on screen while the app is resumed (set by its screen),
 * so the push of a message in it is not shown: the user is reading it.
 */
object OpenWhatsappChat {
    @Volatile
    var chatId: String? = null

    /** The push thread of a chat, as the server sends it (`whatsapp:{id}`). */
    fun thread(chatId: String): String = "whatsapp:$chatId"
}
