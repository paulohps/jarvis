import Foundation
import XCTest
@testable import Networking

final class APIClientTests: XCTestCase {
    private let baseURL = URL(string: "https://jarvis.test")!

    private let meBody = """
    {"data":{"friendly_id":"usr_1","name":"Ana","email":"ana@example.com","avatar_url":null,
    "locale":"pt_BR","timezone":"America/Sao_Paulo","is_super_admin":false,
    "modules":[{"slug":"shopping","name":"Shopping list","is_available_on_mobile":true}]}}
    """

    func testUnauthorizedResponseClearsTheTokenAndEmitsSessionExpired() async throws {
        let tokenStore = InMemoryTokenStore(token: "1|secret")
        let client = APIClient(
            baseURL: baseURL,
            tokenStore: tokenStore,
            transport: StubTransport([(401, #"{"message":"Unauthenticated."}"#)])
        )
        var events = client.sessionEvents().makeAsyncIterator()

        do {
            try await client.send(API.me())
            XCTFail("A 401 must throw.")
        } catch {
            XCTAssertEqual(error, .unauthenticated)
        }

        XCTAssertNil(tokenStore.token())
        let event = await events.next()
        XCTAssertEqual(event, .sessionExpired)
    }

    func testUnauthorizedResponseOfAnyEndpointEndsTheSession() async throws {
        let tokenStore = InMemoryTokenStore(token: "1|secret")
        let client = APIClient(baseURL: baseURL, tokenStore: tokenStore, transport: StubTransport([(401, "{}")]))
        var events = client.sessionEvents().makeAsyncIterator()

        _ = try? await client.send(API.markAllNotificationsRead())

        XCTAssertNil(tokenStore.token())
        let event = await events.next()
        XCTAssertEqual(event, .sessionExpired)
    }

    func testRequestsCarryTheBearerTokenAndTheLanguage() async throws {
        let transport = StubTransport([(200, meBody)])
        let client = APIClient(
            baseURL: baseURL,
            tokenStore: InMemoryTokenStore(token: "1|secret"),
            transport: transport,
            acceptLanguage: { "pt-BR" }
        )

        let user = try await client.send(API.me()).data

        let request = try XCTUnwrap(transport.lastRequest)
        XCTAssertEqual(request.url?.absoluteString, "https://jarvis.test/api/v1/me")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer 1|secret")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Accept-Language"), "pt-BR")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Accept"), "application/json")
        XCTAssertEqual(user.locale, "pt_BR")
        XCTAssertEqual(user.modules, [UserModule(slug: "shopping", name: "Shopping list", isAvailableOnMobile: true)])
    }

    func testSignInSendsNoTokenAndItsRefusalIsNotASessionEvent() async throws {
        let transport = StubTransport([
            (422, #"{"message":"Invitation required.","errors":{"id_token":["Access is by invitation only."]}}"#),
        ])
        let tokenStore = InMemoryTokenStore(token: "stale")
        let client = APIClient(baseURL: baseURL, tokenStore: tokenStore, transport: transport)

        do {
            try await client.send(API.google(idToken: "id-token", deviceName: "iPhone da Ana"))
            XCTFail("A 422 must throw.")
        } catch {
            XCTAssertEqual(error.firstError(for: "id_token"), "Access is by invitation only.")
            XCTAssertEqual(error.displayMessage, "Access is by invitation only.")
        }

        let request = try XCTUnwrap(transport.lastRequest)
        XCTAssertNil(request.value(forHTTPHeaderField: "Authorization"))
        let body = try XCTUnwrap(request.httpBody)
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: String])
        XCTAssertEqual(json, ["id_token": "id-token", "device_name": "iPhone da Ana"])
        XCTAssertEqual(tokenStore.token(), "stale")
    }

    func testForbiddenResponseEmitsAccessDenied() async throws {
        let tokenStore = InMemoryTokenStore(token: "1|secret")
        let client = APIClient(
            baseURL: baseURL,
            tokenStore: tokenStore,
            transport: StubTransport([(403, #"{"message":"This action is unauthorized."}"#)])
        )
        var events = client.sessionEvents().makeAsyncIterator()

        do {
            try await client.send(API.reminderOccurrences(filter: .pending))
            XCTFail("A 403 must throw.")
        } catch {
            XCTAssertEqual(error, .forbidden(message: "This action is unauthorized."))
        }

        XCTAssertEqual(tokenStore.token(), "1|secret")
        let event = await events.next()
        XCTAssertEqual(event, .accessDenied)
    }

    func testDatesAreReadAsISO8601() throws {
        XCTAssertEqual(JSONCoding.parseDate("2026-10-06T12:00:00+00:00"), Date(timeIntervalSince1970: 1_791_288_000))
        XCTAssertEqual(JSONCoding.parseDate("2026-10-06T12:00:00.000000Z"), Date(timeIntervalSince1970: 1_791_288_000))
        XCTAssertNil(JSONCoding.parseDate("06/10/2026"))
    }
}
