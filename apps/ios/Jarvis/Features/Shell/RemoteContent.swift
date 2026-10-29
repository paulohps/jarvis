import Localization
import Networking
import SwiftUI

/// Loads a module screen from the API and shows it, or "No access" when the
/// server answers 403 (the module was switched off; the client already asked
/// the shell to read `/me` again, so the tab goes away).
struct RemoteContent<Value: Sendable, Content: View>: View {
    private enum LoadState {
        case loading
        case loaded(Value)
        case forbidden
        case failed(String)
    }

    let load: @MainActor () async throws -> Value
    @ViewBuilder let content: (Value) -> Content

    @Environment(\.appLanguage) private var language
    @State private var state: LoadState = .loading

    var body: some View {
        Group {
            switch state {
            case .loading:
                ProgressView(language.localized("Loading…"))
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            case .loaded(let value):
                content(value)
            case .forbidden:
                NoAccessView()
            case .failed(let message):
                ContentUnavailableView {
                    Label(message, systemImage: "wifi.exclamationmark")
                } actions: {
                    Button(language.localized("Try again")) {
                        Task { await reload() }
                    }
                    .buttonStyle(.glass)
                }
            }
        }
        .task {
            await reload()
        }
        .refreshable {
            await reload()
        }
    }

    private func reload() async {
        do {
            state = .loaded(try await load())
        } catch APIError.forbidden {
            state = .forbidden
        } catch APIError.unauthenticated {
            // The shell already went back to the login.
        } catch let error as APIError {
            state = .failed(error.userMessage(in: language))
        } catch {
            state = .failed(language.localized("Something went wrong. Try again."))
        }
    }
}
