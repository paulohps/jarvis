import Foundation
import XCTest
@testable import Networking

final class WorkspacesTests: XCTestCase {
    private let meJSON = """
    {"data":{"friendly_id":"usr_1","name":"Ana","email":"ana@example.com","avatar_url":null,"locale":"pt_BR","timezone":"America/Sao_Paulo",
    "is_super_admin":false,"modules":[{"slug":"shopping","name":"Compras","is_available_on_mobile":true}],
    "workspaces":[
      {"id":"wsp_home","name":"Casa","color":"blue","color_hex":"#007aff","workspace_color":"blue","own_color":null,"is_default":false,"modules":["shopping","reminders"]},
      {"id":"wsp_beach","name":"Praia","color":"red","color_hex":"#ff3b30","workspace_color":"green","own_color":"red","is_default":true,"modules":["shopping"]}
    ],
    "broadcast_channel":"private-App.Models.User.1"}}
    """

    func testMeReadsTheWorkspacesWithTheColorTheUserSees() throws {
        let user = try JSONCoding.decoder.decode(DataEnvelope<User>.self, from: Data(meJSON.utf8)).data

        XCTAssertEqual(user.workspaces.map(\.id), ["wsp_home", "wsp_beach"])
        XCTAssertEqual(user.workspaces.last?.ownColor, "red")
        XCTAssertEqual(user.workspaces.last?.workspaceColor, "green")
        XCTAssertEqual(user.workspaces.last?.colorHex, "#ff3b30")
        XCTAssertTrue(user.showsWorkspaces(forModule: "shopping"))
        XCTAssertFalse(user.showsWorkspaces(forModule: "reminders"))
        XCTAssertEqual(user.defaultWorkspace(forModule: "shopping")?.id, "wsp_beach")
        // The default lacks the module: the first one with it.
        XCTAssertEqual(user.defaultWorkspace(forModule: "reminders")?.id, "wsp_home")
        XCTAssertNil(user.defaultWorkspace(forModule: "invoices"))
    }

    func testAMeWithoutWorkspacesAndTheCachedUserStillRead() throws {
        let old = try JSONCoding.decoder.decode(User.self, from: Data("""
        {"friendly_id":"usr_1","name":"Ana","email":"ana@example.com","locale":"en","timezone":"UTC","is_super_admin":true,"modules":[]}
        """.utf8))

        XCTAssertEqual(old.workspaces, [])
        XCTAssertFalse(old.showsWorkspaces(forModule: "shopping"))

        let user = try JSONCoding.decoder.decode(DataEnvelope<User>.self, from: Data(meJSON.utf8)).data
        let cached = try JSONCoding.decoder.decode(User.self, from: JSONCoding.encoder.encode(user))

        XCTAssertEqual(cached, user)
    }

    func testThePreferenceSendsOnlyWhatChangesAndNullForTheWorkspaceColor() throws {
        func json(_ input: WorkspacePreferenceInput) throws -> [String: Any] {
            try XCTUnwrap(JSONSerialization.jsonObject(with: JSONCoding.encoder.encode(input)) as? [String: Any])
        }

        let own = try json(WorkspacePreferenceInput(color: .own("teal")))
        XCTAssertEqual(own["color"] as? String, "teal")
        XCTAssertNil(own["is_default"])

        let reset = try json(WorkspacePreferenceInput(color: .workspaceColor))
        XCTAssertTrue(reset.keys.contains("color"))
        XCTAssertTrue(reset["color"] is NSNull)

        let makeDefault = try json(WorkspacePreferenceInput(makeDefault: true))
        XCTAssertEqual(makeDefault["is_default"] as? Bool, true)
        XCTAssertFalse(makeDefault.keys.contains("color"))

        let endpoint = API.updateWorkspacePreference(id: "wsp_home", WorkspacePreferenceInput(makeDefault: true))
        XCTAssertEqual(endpoint.method, .patch)
        XCTAssertEqual(endpoint.path, "/me/workspaces/wsp_home")
    }

    func testTheWorkspaceIsSentOnlyWhenPicked() throws {
        func json<Response>(_ endpoint: Endpoint<Response>) throws -> [String: Any] {
            try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(endpoint.body)) as? [String: Any])
        }

        XCTAssertNil(try json(API.createShoppingList(name: "Market"))["workspace"])
        XCTAssertEqual(try json(API.createShoppingList(name: "Market", workspace: "wsp_beach"))["workspace"] as? String, "wsp_beach")
        XCTAssertEqual(try json(API.renameShoppingList(id: "shl_1", name: "Market", workspace: "wsp_home"))["workspace"] as? String, "wsp_home")

        let reminder = ReminderInput(title: "Pay", note: nil, dueAt: nil, recurrence: nil, assignedTo: nil)
        XCTAssertFalse(try json(API.createReminder(reminder)).keys.contains("workspace"))

        let moved = ReminderInput(title: "Pay", note: nil, dueAt: nil, recurrence: nil, assignedTo: nil, workspace: "wsp_beach")
        XCTAssertEqual(try json(API.updateReminder(id: "rem_1", moved))["workspace"] as? String, "wsp_beach")
    }

    func testSharedRecordsReadTheirWorkspaceAndOldServersStillWork() throws {
        let list = try JSONCoding.decoder.decode(ShoppingList.self, from: Data("""
        {"friendly_id":"shl_1","name":"Market","is_archived":false,
         "workspace":{"id":"wsp_home","name":"Casa","color":"blue","color_hex":"#007aff"}}
        """.utf8))
        XCTAssertEqual(list.workspace?.colorHex, "#007aff")

        let entries = try JSONCoding.decoder.decode([CalendarEntry].self, from: Data("""
        [{"key":"a","source":"reminders","title":"Pay","subtitle":null,"starts_at":"2026-10-06T12:00:00+00:00","ends_at":null,
          "is_all_day":false,"is_projected":false,"is_completable":true,"is_completed":false,"is_overdue":false,
          "reminder_id":"rem_1","occurrence_id":null,"url":null,
          "workspace":{"id":"wsp_home","name":"Casa","color":"blue","color_hex":"#007aff"}},
         {"key":"b","source":"whatsapp","title":"Hi","starts_at":"2026-10-06T12:00:00+00:00",
          "is_all_day":false,"is_projected":false,"is_completable":false,"is_completed":false,"is_overdue":false,"workspace":null},
         {"key":"c","source":"google","title":"Old","starts_at":"2026-10-06T12:00:00+00:00",
          "is_all_day":true,"is_projected":false,"is_completable":false,"is_completed":false,"is_overdue":false}]
        """.utf8))

        XCTAssertEqual(entries.map(\.workspace?.id), ["wsp_home", nil, nil])
    }

    func testTheAssigneesByWorkspaceKeepTheirIdsAndAnEmptyOneReads() throws {
        let page = try JSONCoding.decoder.decode(ReminderPage.self, from: Data("""
        {"data":{"friendly_id":"rem_1","title":"Pay","is_recurring":false},
         "meta":{"timezone":"UTC","assignees":[{"friendly_id":"usr_1","name":"Ana"}],
          "assignees_by_workspace":{"wsp_home_01":[{"friendly_id":"usr_1","name":"Ana"}],"wsp_beach":[]}}}
        """.utf8))

        XCTAssertEqual(page.meta.assigneesByWorkspace?["wsp_home_01"]?.map(\.name), ["Ana"])
        XCTAssertEqual(page.meta.assigneesByWorkspace?["wsp_beach"], [])

        let empty = try JSONCoding.decoder.decode(ReminderPage.self, from: Data("""
        {"data":{"friendly_id":"rem_1","title":"Pay","is_recurring":false},"meta":{"timezone":"UTC","assignees_by_workspace":[]}}
        """.utf8))
        XCTAssertEqual(empty.meta.assigneesByWorkspace?.values, [:])

        let old = try JSONCoding.decoder.decode(ReminderPage.self, from: Data("""
        {"data":{"friendly_id":"rem_1","title":"Pay","is_recurring":false},"meta":{"timezone":"UTC"}}
        """.utf8))
        XCTAssertNil(old.meta.assigneesByWorkspace)
    }

    func testTheAdminWorkspaceReadsItsMembers() async throws {
        let transport = StubTransport([
            (200, """
            {"data":{"id":"wsp_home","name":"Casa","color":"blue","color_hex":"#007aff","members_count":1,"has_data":true,
             "members":[{"user":{"id":"usr_2","name":"Tom","email":"tom@example.com","avatar_url":null,"is_super_admin":false,"is_deactivated":false},
               "color":null,"effective_color":"blue","effective_color_hex":"#007aff","is_default":true,"modules":["shopping"]}]}}
            """),
            (204, ""),
        ])
        let client = APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|t"), transport: transport)

        let detail = try await client.send(API.adminWorkspace(id: "wsp_home")).data

        XCTAssertTrue(detail.hasData)
        XCTAssertEqual(detail.members.first?.user.name, "Tom")
        XCTAssertNil(detail.members.first?.color)
        XCTAssertEqual(detail.members.first?.modules, ["shopping"])

        try await client.send(API.removeAdminWorkspaceMember(workspaceId: "wsp_home", userId: "usr_2"))

        XCTAssertEqual(transport.lastRequest?.httpMethod, "DELETE")
        XCTAssertEqual(transport.lastRequest?.url?.path(), "/api/v1/admin/workspaces/wsp_home/members/usr_2")
    }

    func testAMemberUpdateSendsTheColorAsNullForTheWorkspaceOwn() throws {
        let endpoint = API.updateAdminWorkspaceMember(
            workspaceId: "wsp_home",
            userId: "usr_2",
            AdminMemberInput(user: "usr_2", modules: ["shopping"], color: nil)
        )
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(endpoint.body)) as? [String: Any])

        XCTAssertNil(json["user"])
        XCTAssertTrue(json["color"] is NSNull)
        XCTAssertEqual(json["modules"] as? [String], ["shopping"])
        XCTAssertNil(json["is_default"])
    }

    func testADeleteRefusedWithDataShowsTheServerMessage() async throws {
        let transport = StubTransport([
            (422, #"{"message":"The workspace has data.","errors":{"workspace":["The workspace still has lists or reminders."]}}"#),
        ])
        let client = APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|t"), transport: transport)

        do {
            try await client.send(API.deleteAdminWorkspace(id: "wsp_home"))
            XCTFail("Expected a validation error")
        } catch {
            XCTAssertEqual(error.firstError(for: "workspace"), "The workspace still has lists or reminders.")
            XCTAssertEqual(error.displayMessage, "The workspace still has lists or reminders.")
        }
    }

    func testInvitationsAndUsersRead() throws {
        let invitations = try JSONCoding.decoder.decode(DataEnvelope<[AdminInvitation]>.self, from: Data("""
        {"data":[{"id":"inv_1","email":"x@example.com","status":"pending","workspace":null,"modules":["shopping"],"locale":"pt_BR",
          "expires_at":"2026-10-14T12:00:00+00:00","created_at":"2026-10-07T12:00:00+00:00"},
         {"id":"inv_2","email":"y@example.com","status":"something_new","workspace":{"id":"wsp_home","name":"Casa","color":"blue","color_hex":"#007aff"},
          "modules":[],"locale":"en","expires_at":null,"created_at":null}]}
        """.utf8)).data

        XCTAssertTrue(invitations[0].isPending)
        XCTAssertNil(invitations[1].knownStatus)
        XCTAssertFalse(invitations[1].isPending)

        let users = try JSONCoding.decoder.decode(DataEnvelope<[AdminUser]>.self, from: Data("""
        {"data":[{"id":"usr_1","name":"Ana","email":"ana@example.com","avatar_url":null,"is_super_admin":true,"is_deactivated":false,
          "last_seen_at":null,"workspaces":[{"id":"wsp_home","name":"Casa","color":"blue","color_hex":"#007aff"}]}]}
        """.utf8)).data

        XCTAssertEqual(users.first?.workspaces.first?.name, "Casa")
        XCTAssertEqual(API.setAdminUserActive(id: "usr_1", isActive: false).path, "/admin/users/usr_1/deactivate")
        XCTAssertEqual(API.setAdminUserActive(id: "usr_1", isActive: true).path, "/admin/users/usr_1/reactivate")
    }
}
