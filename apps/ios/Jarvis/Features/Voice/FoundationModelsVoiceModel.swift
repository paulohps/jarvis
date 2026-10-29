import Foundation
import FoundationModels
import Networking
import Voice

/// The local interpreter (US-10.2): Apple Foundation Models turns the spoken
/// text into the same `VoiceAction` the server's interpreter produces, so
/// the text never leaves the iPhone. It runs only where Apple Intelligence
/// is on, the model is ready and it supports the user's language.
struct FoundationModelsVoiceModel: OnDeviceVoiceModel {
    func isAvailable(for locale: Locale) -> Bool {
        let model = SystemLanguageModel.default

        return model.isAvailable && model.supportsLocale(locale)
    }

    func action(for text: String, locale: Locale, now: Date, timeZone: TimeZone) async throws -> VoiceAction? {
        let session = LanguageModelSession(instructions: Self.instructions(now: now, timeZone: timeZone))
        let command = try await session.respond(to: text, generating: SpokenCommand.self).content

        return command.voiceAction
    }

    /// The instructions of the session: the actions and today's date, so
    /// "amanhã às 9h" becomes a wall clock date.
    private static func instructions(now: Date, timeZone: TimeZone) -> String {
        """
        You turn a spoken command of a family assistant into one action. The \
        command may be in English or Brazilian Portuguese. Keep names as spoken \
        (a list "dessa semana", a contact "Ana"); never translate them.
        Actions:
        - addShoppingItem: add `item` (optional `quantity`) to a shopping `list` (leave it empty if no list is named).
        - checkShoppingItem: check off `item` in a shopping `list`.
        - createReminder: a reminder `title`, optional `dueAt` and `note`.
        - completeReminder: mark the `reminder` named as done.
        - sendWhatsappMessage: send `body` to the contact or group `target`, optional `account`.
        - none: the command asks for none of these.
        Now is \(WallClock.dateTime(now, in: timeZone)) (\(timeZone.identifier)). \
        Write `dueAt` as YYYY-MM-DDTHH:MM on that clock.
        """
    }
}

/// What the on-device model generates for a spoken command.
@Generable
struct SpokenCommand {
    @Guide(description: "The action the command asks for.")
    var kind: Kind

    @Guide(description: "The shopping item, as spoken.")
    var item: String?

    @Guide(description: "The quantity or a note of the shopping item, e.g. 2 litros.")
    var quantity: String?

    @Guide(description: "The shopping list's name, as spoken, without the words 'lista de compras' or 'shopping list'.")
    var list: String?

    @Guide(description: "The title of a new reminder.")
    var title: String?

    @Guide(description: "When a new reminder is due, as YYYY-MM-DDTHH:MM.")
    var dueAt: String?

    @Guide(description: "A note of a new reminder.")
    var note: String?

    @Guide(description: "The reminder to complete, as spoken.")
    var reminder: String?

    @Guide(description: "The WhatsApp contact or group, as spoken.")
    var target: String?

    @Guide(description: "The WhatsApp message to send.")
    var body: String?

    @Guide(description: "The WhatsApp account's nickname, only when one is named.")
    var account: String?

    @Generable
    enum Kind {
        case addShoppingItem
        case checkShoppingItem
        case createReminder
        case completeReminder
        case sendWhatsappMessage
        case none
    }

    /// The action for `POST /voice/actions`; `nil` when no action fits or a required value is missing.
    var voiceAction: VoiceAction? {
        let action: VoiceAction

        switch kind {
        case .addShoppingItem:
            action = VoiceAction(name: .shoppingAddItem, parameters: ["item": item, "quantity": quantity, "list": list])
        case .checkShoppingItem:
            action = VoiceAction(name: .shoppingCheckItem, parameters: ["item": item, "list": list])
        case .createReminder:
            action = VoiceAction(name: .remindersCreate, parameters: ["title": title, "due_at": dueAt, "note": note])
        case .completeReminder:
            action = VoiceAction(name: .remindersComplete, parameters: ["reminder": reminder])
        case .sendWhatsappMessage:
            action = VoiceAction(name: .whatsappSend, parameters: ["target": target, "body": body, "account": account])
        case .none:
            return nil
        }

        return Self.required[action.name, default: []].allSatisfy { action.parameters[$0] != nil } ? action : nil
    }

    private static let required: [VoiceActionName: [String]] = [
        .shoppingAddItem: ["item"],
        .shoppingCheckItem: ["item"],
        .remindersCreate: ["title"],
        .remindersComplete: ["reminder"],
        .whatsappSend: ["target", "body"],
    ]
}
