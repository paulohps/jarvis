import Foundation

/// The actions a voice command can run (`components/schemas/VoiceActionInput`).
public enum VoiceActionName: String, Codable, CaseIterable, Sendable {
    case shoppingAddItem = "shopping.add_item"
    case shoppingCheckItem = "shopping.check_item"
    case remindersCreate = "reminders.create"
    case remindersComplete = "reminders.complete"
    case whatsappSend = "whatsapp.send"
}

/// The structured action of a voice command (US-10.2, US-10.3): what both
/// interpreters produce, the on-device one and the server's, and what
/// `POST /voice/actions` runs. The parameters are as spoken (`dessa semana`)
/// or the friendly id of an option the user picked after a clarification;
/// the keys are the API's (`item`, `due_at`, `target`…), and a parameter left
/// out is `null` on the server.
public struct VoiceAction: Encodable, Equatable, Sendable {
    public let name: VoiceActionName
    public let parameters: [String: String]

    /// - Parameter parameters: blank values are left out.
    public init(name: VoiceActionName, parameters: [String: String?]) {
        self.name = name
        self.parameters = parameters.compactMapValues { value in
            guard let value = value?.trimmingCharacters(in: .whitespacesAndNewlines), !value.isEmpty else {
                return nil
            }

            return value
        }
    }

    /// The same action with a parameter replaced, e.g. by the picked option's id.
    public func with(_ parameter: String, _ value: String?) -> VoiceAction {
        var parameters: [String: String?] = self.parameters
        parameters[parameter] = .some(value)

        return VoiceAction(name: name, parameters: parameters)
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(name, forKey: .action)
        try container.encode(parameters, forKey: .parameters)
    }

    enum CodingKeys: String, CodingKey {
        case action
        case parameters
    }
}

/// `VoiceCommandResult.status`.
public enum VoiceStatus: String, Decodable, Sendable {
    /// The action ran.
    case done

    /// Nothing ran: confirm with `confirmation.token` (every `whatsapp.send`).
    case needsConfirmation = "needs_confirmation"

    /// More than one candidate: send the action again with the picked option.
    case needsClarification = "needs_clarification"

    /// The action's module is switched off for the user.
    case forbidden

    /// Not understood, nothing found, or refused by the action's validation.
    case failed
}

/// An option of a `needs_clarification` answer.
public struct VoiceOption: Decodable, Equatable, Hashable, Sendable, Identifiable {
    /// A friendly id.
    public let id: String
    public let label: String

    public init(id: String, label: String) {
        self.id = id
        self.label = label
    }
}

/// Which parameter the user has to pick, and the candidates.
public struct VoiceClarification: Decodable, Equatable, Sendable {
    /// `list`, `item`, `reminder` or `target`.
    public let parameter: String
    public let options: [VoiceOption]

    public init(parameter: String, options: [VoiceOption]) {
        self.parameter = parameter
        self.options = options
    }
}

/// What a voice WhatsApp send would send, for the user to confirm.
public struct VoiceConfirmationSummary: Decodable, Equatable, Sendable {
    public let account: String
    public let target: String
    public let targetType: WhatsappTargetType?
    public let body: String

    public init(account: String, target: String, targetType: WhatsappTargetType?, body: String) {
        self.account = account
        self.target = target
        self.targetType = targetType
        self.body = body
    }
}

/// The token of a `needs_confirmation` answer: it works once, for 5 minutes.
public struct VoiceConfirmation: Decodable, Equatable, Sendable {
    public let token: String
    public let expiresAt: Date?
    public let summary: VoiceConfirmationSummary

    public init(token: String, expiresAt: Date?, summary: VoiceConfirmationSummary) {
        self.token = token
        self.expiresAt = expiresAt
        self.summary = summary
    }
}

/// `components/schemas/VoiceCommandResult`: what a command came to. The
/// `message` comes translated, for the screen and the speech.
public struct VoiceCommandResult: Decodable, Equatable, Sendable {
    /// `nil` when the command was not understood.
    public let action: VoiceActionName?
    public let status: VoiceStatus
    public let message: String

    /// The action's parameters as understood, keyed as the API names them.
    public let parameters: [String: String]
    public let clarification: VoiceClarification?
    public let confirmation: VoiceConfirmation?

    public init(
        action: VoiceActionName?,
        status: VoiceStatus,
        message: String,
        parameters: [String: String] = [:],
        clarification: VoiceClarification? = nil,
        confirmation: VoiceConfirmation? = nil
    ) {
        self.action = action
        self.status = status
        self.message = message
        self.parameters = parameters
        self.clarification = clarification
        self.confirmation = confirmation
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        action = try container.decodeIfPresent(VoiceActionName.self, forKey: .action)
        status = try container.decode(VoiceStatus.self, forKey: .status)
        message = try container.decode(String.self, forKey: .message)
        clarification = try container.decodeIfPresent(VoiceClarification.self, forKey: .clarification)
        confirmation = try container.decodeIfPresent(VoiceConfirmation.self, forKey: .confirmation)

        // The API's decoder turns the keys to camelCase (`due_at` → `dueAt`);
        // the parameters go back to the server, so they keep the API's names.
        let decoded = try container.decodeIfPresent([String: String?].self, forKey: .parameters) ?? [:]
        parameters = Dictionary(
            decoded.compactMap { key, value in value.map { (Self.snakeCased(key), $0) } },
            uniquingKeysWith: { first, _ in first }
        )
    }

    /// The action to send again once the user picked an option of the clarification.
    public func action(choosing option: VoiceOption) -> VoiceAction? {
        guard let action, let clarification else {
            return nil
        }

        return VoiceAction(name: action, parameters: parameters).with(clarification.parameter, option.id)
    }

    enum CodingKeys: String, CodingKey {
        case action
        case status
        case message
        case parameters
        case clarification
        case confirmation
    }

    private static func snakeCased(_ key: String) -> String {
        key.reduce(into: "") { result, character in
            if character.isUppercase {
                result += "_" + character.lowercased()
            } else {
                result.append(character)
            }
        }
    }
}
