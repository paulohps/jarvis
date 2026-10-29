import Foundation
import XCTest
@testable import Networking
@testable import Push

final class PushRegistrationTests: XCTestCase {
    private func makeClient(_ transport: StubTransport, signedIn: Bool = true) -> APIClient {
        APIClient(
            baseURL: URL(string: "https://jarvis.test")!,
            tokenStore: InMemoryTokenStore(token: signedIn ? "1|secret" : nil),
            transport: transport
        )
    }

    private func body(of request: URLRequest?) throws -> [String: Any] {
        try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(request?.httpBody)) as? [String: Any])
    }

    private let deviceSaved = (201, #"{"data":{"friendly_id":"dev_1","name":"iPhone","platform":"ios","has_push_token":true,"is_current":true}}"#)

    func testAllowingPushSendsTheFcmTokenToDevices() async throws {
        let transport = StubTransport([deviceSaved])
        let registration = PushRegistration(client: makeClient(transport), deviceName: "iPhone da Ana", authorizer: FakeAuthorizer(.notDetermined, answer: true))

        let shouldRegister = await registration.start()
        await registration.didReceive(fcmToken: "fcm-token-1")

        XCTAssertTrue(shouldRegister)
        XCTAssertEqual(transport.requests.count, 1)
        XCTAssertEqual(transport.lastRequest?.url?.path, "/api/v1/devices")
        XCTAssertEqual(transport.lastRequest?.httpMethod, "POST")
        let json = try body(of: transport.lastRequest)
        XCTAssertEqual(json["platform"] as? String, "ios")
        XCTAssertEqual(json["name"] as? String, "iPhone da Ana")
        XCTAssertEqual(json["push_token"] as? String, "fcm-token-1")
    }

    func testTheSameTokenIsSentOnceAndARotatedOneAgain() async throws {
        let transport = StubTransport([deviceSaved, deviceSaved])
        let registration = PushRegistration(client: makeClient(transport), deviceName: "iPhone", authorizer: FakeAuthorizer(.authorized))

        await registration.start()
        await registration.didReceive(fcmToken: "fcm-token-1")
        await registration.didReceive(fcmToken: "fcm-token-1")
        await registration.refreshAuthorization()
        await registration.didReceive(fcmToken: "fcm-token-2")

        XCTAssertEqual(transport.requests.count, 2)
        XCTAssertEqual(try body(of: transport.lastRequest)["push_token"] as? String, "fcm-token-2")
    }

    func testDenyingThePermissionDoesNotBreakAndClearsThePushToken() async throws {
        let transport = StubTransport([deviceSaved])
        let registration = PushRegistration(client: makeClient(transport), deviceName: "iPhone", authorizer: FakeAuthorizer(.notDetermined, answer: false))

        let shouldRegister = await registration.start()
        await registration.didReceive(fcmToken: "fcm-token-1")

        XCTAssertFalse(shouldRegister)
        let authorization = await registration.authorization
        XCTAssertEqual(authorization, .denied)
        XCTAssertEqual(transport.requests.count, 1)
        XCTAssertTrue(try body(of: transport.lastRequest)["push_token"] is NSNull)
    }

    func testTheTokenWaitsForTheSignIn() async throws {
        let transport = StubTransport([deviceSaved])
        let tokenStore = InMemoryTokenStore()
        let client = APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: tokenStore, transport: transport)
        let registration = PushRegistration(client: client, deviceName: "iPhone", authorizer: FakeAuthorizer(.authorized))

        await registration.didReceive(fcmToken: "fcm-token-1")
        XCTAssertEqual(transport.requests.count, 0)

        try tokenStore.save("1|secret")
        await registration.start()

        XCTAssertEqual(transport.requests.count, 1)
        XCTAssertEqual(transport.lastRequest?.value(forHTTPHeaderField: "Authorization"), "Bearer 1|secret")
    }

    func testANewSessionRegistersItsDeviceAgain() async throws {
        let transport = StubTransport([deviceSaved, deviceSaved])
        let registration = PushRegistration(client: makeClient(transport), deviceName: "iPhone", authorizer: FakeAuthorizer(.authorized))

        await registration.start()
        await registration.didReceive(fcmToken: "fcm-token-1")
        await registration.reset()
        await registration.start()

        XCTAssertEqual(transport.requests.count, 2)
        XCTAssertEqual(try body(of: transport.lastRequest)["push_token"] as? String, "fcm-token-1")
    }
}

private struct FakeAuthorizer: PushAuthorizing {
    let status: PushAuthorization
    var answer = false

    init(_ status: PushAuthorization, answer: Bool = false) {
        self.status = status
        self.answer = answer
    }

    func currentAuthorization() async -> PushAuthorization {
        status
    }

    func requestAuthorization() async -> Bool {
        answer
    }
}
