import AppIntents
import Foundation
import Networking
import Voice

/// A Siri intent of Jarvis (US-10.1, US-10.3): it calls `POST /voice/actions`
/// and carries on the server's answer in the Siri conversation, asking which
/// one on a `needs_clarification` and a confirmation before a WhatsApp send.
protocol VoiceCommandIntent: AppIntent {
    var request: VoiceIntentRequest { get }
}

extension VoiceCommandIntent {
    func performVoiceCommand() async throws -> some IntentResult & ProvidesDialog {
        let service = VoiceIntentServices.service
        let language = VoiceIntentServices.language

        do {
            var result = try await service.run(request, in: VoiceIntentServices.timeZone)

            // A clarification may be followed by a confirmation; the server ends in the rest.
            for _ in 0..<3 {
                switch result.status {
                case .needsClarification:
                    guard let option = try await requestOption(of: result) else {
                        return .result(dialog: "\(language.localized("Command cancelled."))")
                    }

                    result = try await service.choose(option, in: result)
                case .needsConfirmation:
                    guard let confirmation = result.confirmation else {
                        return .result(dialog: "\(result.message)")
                    }

                    // Throws when the user cancels: nothing is sent.
                    try await requestConfirmation(
                        actionName: .send,
                        dialog: "\(VoiceConfirmationText.prompt(for: confirmation.summary, in: language))"
                    )
                    result = try await service.confirm(confirmation)
                default:
                    return .result(dialog: "\(result.message)")
                }
            }

            return .result(dialog: "\(result.message)")
        } catch let error as APIError {
            return .result(dialog: "\(VoiceIntentServices.message(for: error))")
        }
    }

    /// Asks which candidate in the Siri conversation.
    private func requestOption(of result: VoiceCommandResult) async throws -> VoiceOption? {
        let options = result.clarification?.options ?? []
        let choices = options.map { IntentChoiceOption(title: "\($0.label)", style: .default) }

        guard !choices.isEmpty else {
            return nil
        }

        let chosen = try await requestChoice(between: choices, dialog: IntentDialog("\(result.message)"))

        return choices.firstIndex(of: chosen).map { options[$0] }
    }
}

extension VoiceIntentServices {
    /// What Siri says when the call failed.
    static func message(for error: APIError) -> String {
        switch error {
        case .unauthenticated:
            language.localized("Sign in to Jarvis on this iPhone first.")
        case .transport:
            language.localized("Could not reach the server. Check your connection and try again.")
        default:
            error.displayMessage ?? language.localized("Something went wrong. Try again.")
        }
    }
}

struct AddShoppingItemIntent: VoiceCommandIntent {
    static var title: LocalizedStringResource { "Add shopping item" }
    static var description: IntentDescription { IntentDescription("Adds an item to a shopping list of Jarvis.") }

    @Parameter(title: "Item", requestValueDialog: "What should I add?")
    var item: String

    @Parameter(title: "Quantity")
    var quantity: String?

    @Parameter(title: "List")
    var list: String?

    static var parameterSummary: some ParameterSummary {
        Summary("Add \(\.$item) to \(\.$list)") {
            \.$quantity
        }
    }

    var request: VoiceIntentRequest {
        .addShoppingItem(item: item, quantity: quantity, list: list)
    }

    func perform() async throws -> some IntentResult & ProvidesDialog {
        try await performVoiceCommand()
    }
}

struct CreateReminderIntent: VoiceCommandIntent {
    static var title: LocalizedStringResource { "Create reminder" }
    static var description: IntentDescription { IntentDescription("Creates a reminder in Jarvis.") }

    @Parameter(title: "Title", requestValueDialog: "What should I remind you of?")
    var reminderTitle: String

    @Parameter(title: "Date and time")
    var dueAt: Date?

    @Parameter(title: "Note")
    var note: String?

    static var parameterSummary: some ParameterSummary {
        Summary("Remind me of \(\.$reminderTitle) at \(\.$dueAt)") {
            \.$note
        }
    }

    var request: VoiceIntentRequest {
        .createReminder(title: reminderTitle, dueAt: dueAt, note: note)
    }

    func perform() async throws -> some IntentResult & ProvidesDialog {
        try await performVoiceCommand()
    }
}

struct CompleteReminderIntent: VoiceCommandIntent {
    static var title: LocalizedStringResource { "Complete reminder" }
    static var description: IntentDescription { IntentDescription("Marks a reminder of Jarvis as done.") }

    @Parameter(title: "Reminder", requestValueDialog: "Which reminder?")
    var reminder: String

    static var parameterSummary: some ParameterSummary {
        Summary("Complete \(\.$reminder)")
    }

    var request: VoiceIntentRequest {
        .completeReminder(reminder: reminder)
    }

    func perform() async throws -> some IntentResult & ProvidesDialog {
        try await performVoiceCommand()
    }
}

struct SendWhatsappMessageIntent: VoiceCommandIntent {
    static var title: LocalizedStringResource { "Send WhatsApp message" }
    static var description: IntentDescription { IntentDescription("Sends a WhatsApp message through Jarvis, after you confirm it.") }

    @Parameter(title: "Contact or group", requestValueDialog: "To whom?")
    var target: String

    @Parameter(title: "Message", requestValueDialog: "What should the message say?")
    var body: String

    @Parameter(title: "Account")
    var account: String?

    static var parameterSummary: some ParameterSummary {
        Summary("Send \(\.$body) to \(\.$target)") {
            \.$account
        }
    }

    var request: VoiceIntentRequest {
        .sendWhatsappMessage(target: target, body: body, account: account)
    }

    func perform() async throws -> some IntentResult & ProvidesDialog {
        try await performVoiceCommand()
    }
}

/// The phrases of the intents for Siri: always with the app's name, the
/// trigger iOS allows (no wake word of its own).
struct JarvisShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(
            intent: AddShoppingItemIntent(),
            phrases: [
                "Add a shopping item in \(.applicationName)",
                "Add to the shopping list in \(.applicationName)",
            ],
            shortTitle: "Add shopping item",
            systemImageName: "cart.badge.plus"
        )
        AppShortcut(
            intent: CreateReminderIntent(),
            phrases: [
                "Create a reminder in \(.applicationName)",
                "Remind me with \(.applicationName)",
            ],
            shortTitle: "Create reminder",
            systemImageName: "bell.badge"
        )
        AppShortcut(
            intent: CompleteReminderIntent(),
            phrases: [
                "Complete a reminder in \(.applicationName)",
                "Mark a reminder done in \(.applicationName)",
            ],
            shortTitle: "Complete reminder",
            systemImageName: "checkmark.circle"
        )
        AppShortcut(
            intent: SendWhatsappMessageIntent(),
            phrases: [
                "Send a WhatsApp message with \(.applicationName)",
                "Send a message with \(.applicationName)",
            ],
            shortTitle: "Send WhatsApp message",
            systemImageName: "message"
        )
    }
}
