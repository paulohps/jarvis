import Foundation
import XCTest
@testable import Auth
import Networking
import Security

final class TokenStoreTests: XCTestCase {
    private var service = ""

    override func setUp() async throws {
        service = "app.jarvis.ios.tests.\(UUID().uuidString)"
    }

    override func tearDown() async throws {
        KeychainTokenStore(service: service).clear()
    }

    func testTheTokenPersistsInTheKeychain() throws {
        try requireKeychain()
        try KeychainTokenStore(service: service).save("1|first")

        // A new store, as after the app is relaunched, reads the same item.
        XCTAssertEqual(KeychainTokenStore(service: service).token(), "1|first")

        try KeychainTokenStore(service: service).save("2|second")
        XCTAssertEqual(KeychainTokenStore(service: service).token(), "2|second")
    }

    func testClearRemovesTheTokenFromTheKeychain() throws {
        try requireKeychain()
        let store = KeychainTokenStore(service: service)
        try store.save("1|secret")

        store.clear()

        XCTAssertNil(KeychainTokenStore(service: service).token())
    }

    func testSignInSavesTheToken() async throws {
        let store = InMemoryTokenStore()
        let transport = StubTransport([(200, Self.tokenIssuedBody)])
        let auth = AuthService(
            client: APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: store, transport: transport),
            deviceName: { "iPhone da Ana" }
        )

        let user = try await auth.login(email: "ana@example.com", password: "secret")

        XCTAssertEqual(user.email, "ana@example.com")
        XCTAssertEqual(store.token(), "7|issued")
        let body = try XCTUnwrap(transport.lastRequest?.httpBody)
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: String])
        XCTAssertEqual(json["device_name"], "iPhone da Ana")
    }

    func testLogoutRevokesTheTokenAndForgetsIt() async throws {
        let store = InMemoryTokenStore(token: "7|issued")
        let transport = StubTransport([(204, "")])
        let auth = AuthService(
            client: APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: store, transport: transport),
            deviceName: { "iPhone" }
        )

        await auth.logout()

        XCTAssertNil(store.token())
        XCTAssertEqual(transport.lastRequest?.url?.path, "/api/v1/auth/logout")
        XCTAssertEqual(transport.lastRequest?.value(forHTTPHeaderField: "Authorization"), "Bearer 7|issued")
    }

    func testLogoutForgetsTheTokenEvenWhenTheServerIsUnreachable() async throws {
        let store = InMemoryTokenStore(token: "7|issued")
        let auth = AuthService(
            client: APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: store, transport: StubTransport([(503, "{}")])),
            deviceName: { "iPhone" }
        )

        await auth.logout()

        XCTAssertNil(store.token())
    }

    /// A hostless test bundle on the simulator has no keychain access group,
    /// so the Keychain answers `errSecMissingEntitlement`; those runs skip the
    /// Keychain tests instead of failing them.
    private func requireKeychain() throws {
        do {
            try KeychainTokenStore(service: service).save("probe")
        } catch let error as KeychainError where error.status == errSecMissingEntitlement {
            throw XCTSkip("The Keychain is unavailable to this test bundle (errSecMissingEntitlement).")
        }
    }

    private static let tokenIssuedBody = """
    {"token":"7|issued","user":{"friendly_id":"usr_1","name":"Ana","email":"ana@example.com","avatar_url":null,
    "locale":"en","timezone":"UTC","is_super_admin":false,"modules":[]}}
    """
}
