import Networking

/// What the WhatsApp tab pushes: a chat, by the friendly id of its contact
/// or group, with the chat itself when it was opened from the list (a push
/// only knows the id; the screen finds the rest), or the archived chats.
enum WhatsappDestination: Hashable {
    case chat(id: String, preview: WhatsappConversation?)
    case archived
}
