import Localization
import Networking
import SwiftUI

extension APIError {
    /// What a screen shows for a failed call, in the app's language: the
    /// server's message (a 422 comes translated) or a generic one.
    func userMessage(in language: AppLanguage) -> String {
        switch self {
        case .transport:
            language.localized("Could not reach the server. Check your connection and try again.")
        default:
            displayMessage ?? language.localized("Something went wrong. Try again.")
        }
    }

    /// No answer from the server: the call may be tried again later.
    var isOffline: Bool {
        if case .transport = self {
            return true
        }

        return false
    }
}

/// An alert with the message of a failed change; dismissing it clears the failure.
private struct ActionFailureAlert: ViewModifier {
    @Binding var failure: APIError?

    @Environment(\.appLanguage) private var language

    func body(content: Content) -> some View {
        content.alert(
            language.localized("Could not save"),
            isPresented: Binding { failure != nil && failure != .unauthenticated } set: { if !$0 { failure = nil } },
            presenting: failure
        ) { _ in
            Button(language.localized("OK"), role: .cancel) {}
        } message: { failure in
            Text(verbatim: failure.userMessage(in: language))
        }
    }
}

extension View {
    func actionFailureAlert(_ failure: Binding<APIError?>) -> some View {
        modifier(ActionFailureAlert(failure: failure))
    }
}
