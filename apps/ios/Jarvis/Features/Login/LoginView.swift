import Localization
import Networking
import SwiftUI

/// The sign-in (US-1.5, US-1.3): e-mail and password, or "Continue with
/// Google". Access is by invitation only, so the server's refusal ("invitation
/// required", already in the app's language) is shown as it comes.
struct LoginView: View {
    let notice: String?

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var email = ""
    @State private var password = ""
    @State private var errorMessage: String?
    @State private var isSubmitting = false
    @FocusState private var focusedField: Field?

    private enum Field {
        case email
        case password
    }

    private var google: GoogleSignInProvider {
        GoogleSignInProvider(clientID: model.configuration.googleClientID)
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 28) {
                header

                if let message = errorMessage ?? notice {
                    Label(message, systemImage: "exclamationmark.circle.fill")
                        .font(.subheadline)
                        .foregroundStyle(errorMessage == nil ? Color.secondary : Color.red)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(14)
                        .background(.background.secondary, in: .rect(cornerRadius: 16))
                        .accessibilityIdentifier("login.error")
                }

                credentials

                Button(action: signIn) {
                    Group {
                        if isSubmitting {
                            ProgressView()
                        } else {
                            language.text("Sign in")
                        }
                    }
                    .frame(maxWidth: .infinity, minHeight: 30)
                }
                .buttonStyle(.glassProminent)
                .controlSize(.large)
                .disabled(isSubmitting)
                .accessibilityIdentifier("login.submit")

                divider

                Button(action: signInWithGoogle) {
                    HStack(spacing: 10) {
                        Image(systemName: "g.circle.fill")
                            .font(.title3)
                        language.text("Continue with Google")
                    }
                    .frame(maxWidth: .infinity, minHeight: 30)
                }
                .buttonStyle(.glass)
                .controlSize(.large)
                .disabled(isSubmitting)
                .accessibilityIdentifier("login.google")
            }
            .padding(.horizontal, 24)
            .padding(.vertical, 48)
            .frame(maxWidth: 480)
            .frame(maxWidth: .infinity)
        }
        .scrollDismissesKeyboard(.interactively)
        .background(Color(.systemGroupedBackground))
    }

    private var header: some View {
        VStack(spacing: 12) {
            Image(.brandIcon)
                .resizable()
                .frame(width: 84, height: 84)
                .clipShape(.rect(cornerRadius: 19))
                .shadow(color: .black.opacity(0.15), radius: 12, y: 6)
                .accessibilityHidden(true)

            language.text("Jarvis")
                .font(.largeTitle.bold())

            language.text("Your family's daily routines, in one place.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
        }
    }

    private var credentials: some View {
        VStack(spacing: 0) {
            TextField(language.localized("Email"), text: $email)
                .textContentType(.username)
                .keyboardType(.emailAddress)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .submitLabel(.next)
                .focused($focusedField, equals: .email)
                .onSubmit { focusedField = .password }
                .padding(16)
                .accessibilityIdentifier("login.email")

            Divider()
                .padding(.leading, 16)

            SecureField(language.localized("Password"), text: $password)
                .textContentType(.password)
                .submitLabel(.go)
                .focused($focusedField, equals: .password)
                .onSubmit(signIn)
                .padding(16)
                .accessibilityIdentifier("login.password")
        }
        .background(Color(.secondarySystemGroupedBackground), in: .rect(cornerRadius: 20))
    }

    private var divider: some View {
        HStack(spacing: 12) {
            Rectangle().fill(.separator).frame(height: 1)
            language.text("or")
                .font(.footnote)
                .foregroundStyle(.secondary)
            Rectangle().fill(.separator).frame(height: 1)
        }
    }

    private func signIn() {
        let email = email.trimmingCharacters(in: .whitespacesAndNewlines)

        guard !email.isEmpty, !password.isEmpty else {
            errorMessage = language.localized("Enter your email and password.")

            return
        }

        submit {
            try await model.auth.login(email: email, password: password)
        }
    }

    private func signInWithGoogle() {
        submit {
            let idToken: String

            do throws(GoogleSignInProvider.Failure) {
                idToken = try await google.idToken()
            } catch .cancelled {
                return nil
            } catch .notConfigured {
                throw SignInError.message(language.localized("Google sign-in is not configured in this build."))
            } catch {
                throw SignInError.message(language.localized("Something went wrong. Try again."))
            }

            return try await model.auth.loginWithGoogle(idToken: idToken)
        }
    }

    private func submit(_ operation: @escaping @MainActor () async throws -> User?) {
        focusedField = nil
        errorMessage = nil
        isSubmitting = true

        Task {
            defer { isSubmitting = false }

            do {
                if let user = try await operation() {
                    password = ""
                    model.didSignIn(as: user)
                }
            } catch let error as APIError {
                errorMessage = message(for: error)
            } catch SignInError.message(let message) {
                errorMessage = message
            } catch {
                errorMessage = language.localized("Something went wrong. Try again.")
            }
        }
    }

    private func message(for error: APIError) -> String {
        switch error {
        case .transport:
            language.localized("Could not reach the server. Check your connection and try again.")
        case .validation, .tooManyRequests:
            error.displayMessage ?? language.localized("Access is by invitation only. Ask the admin for an invitation.")
        default:
            language.localized("Something went wrong. Try again.")
        }
    }

    private enum SignInError: Error {
        case message(String)
    }
}
