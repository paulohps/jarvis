import GoogleSignIn
import UIKit

/// The native Google Sign-In (US-1.5, US-1.3): it gives back the ID token the
/// server checks in `POST /auth/google` (audience, issuer, expiry).
@MainActor
struct GoogleSignInProvider {
    enum Failure: Error {
        case notConfigured
        case cancelled
        case missingIDToken
        case failed(String)
    }

    let clientID: String?

    var isConfigured: Bool {
        clientID != nil
    }

    func idToken() async throws(Failure) -> String {
        guard let clientID else {
            throw .notConfigured
        }

        guard let presenter = Self.topViewController() else {
            throw .failed("No window to present the Google Sign-In.")
        }

        GIDSignIn.sharedInstance.configuration = GIDConfiguration(clientID: clientID)

        do {
            let result = try await GIDSignIn.sharedInstance.signIn(withPresenting: presenter)

            guard let idToken = result.user.idToken?.tokenString else {
                throw Failure.missingIDToken
            }

            // The app keeps only the Sanctum token; the Google session is not needed afterwards.
            GIDSignIn.sharedInstance.signOut()

            return idToken
        } catch let failure as Failure {
            throw failure
        } catch let error as NSError where error.domain == kGIDSignInErrorDomain && error.code == GIDSignInError.canceled.rawValue {
            throw .cancelled
        } catch {
            throw .failed(error.localizedDescription)
        }
    }

    /// Hands the redirect back to the SDK (`onOpenURL`); `true` when it was Google's.
    static func handle(_ url: URL) -> Bool {
        GIDSignIn.sharedInstance.handle(url)
    }

    private static func topViewController() -> UIViewController? {
        let scene = UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .first { $0.activationState == .foregroundActive } ?? UIApplication.shared.connectedScenes.first as? UIWindowScene
        var controller = scene?.keyWindow?.rootViewController

        while let presented = controller?.presentedViewController {
            controller = presented
        }

        return controller
    }
}
