import Foundation
import XCTest
@testable import Networking

final class ShoppingListTests: XCTestCase {
    func testASingleListIsReadWithItsItems() throws {
        let list = try JSONCoding.decoder.decode(DataEnvelope<ShoppingList>.self, from: Data("""
        {"data":{"friendly_id":"shl_1","name":"Market","is_archived":false,"archived_at":null,
        "items":[
          {"friendly_id":"shi_1","name":"Milk","quantity_note":"2 L","position":1,"is_checked":false,"checked_at":null,
           "checked_by":null,"client_updated_at":null,"created_at":"2026-10-06T12:00:00+00:00","updated_at":"2026-10-06T12:00:00+00:00"},
          {"friendly_id":"shi_2","name":"Bread","quantity_note":null,"position":2,"is_checked":true,"checked_at":"2026-10-06T12:05:00+00:00",
           "checked_by":{"friendly_id":"usr_2","name":"Tom"},"client_updated_at":"2026-10-06T12:05:00+00:00",
           "created_at":"2026-10-06T12:00:00+00:00","updated_at":"2026-10-06T12:05:00+00:00"}
        ],
        "created_at":"2026-10-06T12:00:00+00:00","updated_at":"2026-10-06T12:05:00+00:00"}}
        """.utf8)).data

        XCTAssertEqual(list.broadcastChannel, "private-shopping-lists.shl_1")
        // A server that does not send `is_default` yet reads as not the default.
        XCTAssertFalse(list.isDefault)
        XCTAssertNil(list.uncheckedItemsCount)
        XCTAssertEqual(list.items?.map(\.name), ["Milk", "Bread"])
        XCTAssertEqual(list.items?.first?.quantityNote, "2 L")
        XCTAssertEqual(list.items?.last?.checkedBy?.name, "Tom")
    }

    func testTheDefaultIsSetWithPostAndUnsetWithDelete() async throws {
        let transport = StubTransport([
            (200, #"{"data":{"friendly_id":"shl_1","name":"Market","is_archived":false,"is_default":true}}"#),
            (200, #"{"data":{"friendly_id":"shl_1","name":"Market","is_archived":false,"is_default":false}}"#),
        ])
        let client = APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|t"), transport: transport)

        let made = try await client.send(API.setDefaultShoppingList(id: "shl_1", isDefault: true)).data

        XCTAssertTrue(made.isDefault)
        XCTAssertEqual(transport.lastRequest?.httpMethod, "POST")
        XCTAssertEqual(transport.lastRequest?.url?.path(), "/api/v1/shopping/lists/shl_1/default")

        let unset = try await client.send(API.setDefaultShoppingList(id: "shl_1", isDefault: false)).data

        XCTAssertFalse(unset.isDefault)
        XCTAssertEqual(transport.lastRequest?.httpMethod, "DELETE")
    }

    func testCheckedItemsGoToTheEndInTheOrderTheyWereChecked() {
        let first = Date(timeIntervalSince1970: 100)
        let later = Date(timeIntervalSince1970: 200)
        let items: [ShoppingListItem] = [
            ShoppingListItem(friendlyId: "shi_eggs", name: "Eggs", position: 1, isChecked: true, checkedAt: later),
            ShoppingListItem(friendlyId: "shi_milk", name: "Milk", position: 2),
            ShoppingListItem(friendlyId: "shi_rice", name: "Rice", position: 3, isChecked: true, checkedAt: first),
            ShoppingListItem(friendlyId: "shi_bread", name: "Bread", position: 0),
        ]

        XCTAssertEqual(items.sortedForDisplay().map(\.name), ["Bread", "Milk", "Rice", "Eggs"])
    }

    func testReorderingPutsTheGivenItemsFirstAndKeepsTheOthersAfter() {
        let items: [ShoppingListItem] = [
            ShoppingListItem(friendlyId: "shi_milk", name: "Milk", position: 0),
            ShoppingListItem(friendlyId: "shi_bread", name: "Bread", position: 1),
            ShoppingListItem(friendlyId: "shi_eggs", name: "Eggs", position: 2),
            ShoppingListItem(friendlyId: "shi_rice", name: "Rice", position: 3),
        ]

        let reordered = items.reordered(["shi_rice", "shi_gone", "shi_milk"])

        XCTAssertEqual(reordered.sortedForDisplay().map(\.name), ["Rice", "Milk", "Bread", "Eggs"])
        XCTAssertEqual(reordered.map(\.position), [0, 1, 2, 3])
    }

    func testTheReorderSendsTheItemIdsInTheirNewOrder() throws {
        let endpoint = API.reorderShoppingItems(listId: "shl_1", itemIds: ["shi_2", "shi_1"])
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(endpoint.body)) as? [String: Any])

        XCTAssertEqual(endpoint.path, "/shopping/lists/shl_1/reorder")
        XCTAssertEqual(json["items"] as? [String], ["shi_2", "shi_1"])
    }

    func testTheToggleSendsTheStateToEndInAndTheMomentOfTheTap() async throws {
        let transport = StubTransport([(200, #"{"data":{"friendly_id":"shi_1","name":"Milk","position":1,"is_checked":true}}"#)])
        let client = APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|t"), transport: transport)
        let tappedAt = try XCTUnwrap(JSONCoding.parseDate("2026-10-06T12:00:00+00:00"))

        let item = try await client.send(API.toggleShoppingItem(id: "shi_1", checked: true, clientUpdatedAt: tappedAt)).data

        XCTAssertTrue(item.isChecked)
        let request = try XCTUnwrap(transport.lastRequest)
        XCTAssertEqual(request.httpMethod, "POST")
        XCTAssertEqual(request.url?.path(), "/api/v1/shopping/items/shi_1/toggle")
        let body = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(request.httpBody)) as? [String: Any])
        XCTAssertEqual(body["checked"] as? Bool, true)
        XCTAssertEqual(body["client_updated_at"] as? String, "2026-10-06T12:00:00Z")
    }

    func testEditingAnItemCanClearItsNote() throws {
        let body = try XCTUnwrap(API.updateShoppingItem(id: "shi_1", name: "Milk", quantityNote: nil).body)
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])

        XCTAssertEqual(json["name"] as? String, "Milk")
        XCTAssertTrue(json["quantity_note"] is NSNull)
    }

    func testARecurringListIsReadWithItsDeadlineAndItsOpenList() throws {
        let list = try JSONCoding.decoder.decode(DataEnvelope<ShoppingList>.self, from: Data("""
        {"data":{"friendly_id":"shl_1","name":"Market 09/10","is_archived":false,"is_recurring":true,"schedule_id":"sls_1",
        "due_at":"2026-10-09T21:00:00+00:00","is_overdue":true,"completed_at":null}}
        """.utf8)).data

        XCTAssertEqual(list.scheduleId, "sls_1")
        XCTAssertEqual(list.dueAt, JSONCoding.parseDate("2026-10-09T21:00:00+00:00"))
        XCTAssertTrue(list.isOverdue)

        let schedule = try JSONCoding.decoder.decode(DataEnvelope<ShoppingListSchedule>.self, from: Data("""
        {"data":{"friendly_id":"sls_1","name":"Market","next_list_content":"template_and_pending","closes_automatically":false,
        "waits_for_close":true,"template_items":["Milk","Bread"],
        "recurrence":{"frequency":"weekly","time_of_day":"18:00","weekdays":["friday"],"day_of_month":null,"is_last_day_of_month":false,
        "cron_expression":null,"timezone":"America/Sao_Paulo","starts_at":"2026-10-06T12:00:00+00:00","ends_at":null,
        "next_run_at":"2026-10-09T21:00:00+00:00","is_paused":false},
        "open_list":{"friendly_id":"shl_1","name":"Market 09/10","due_at":"2026-10-09T21:00:00+00:00","is_overdue":false}}}
        """.utf8)).data

        XCTAssertEqual(schedule.nextListContent, .templateAndPending)
        XCTAssertEqual(schedule.templateItems, ["Milk", "Bread"])
        XCTAssertEqual(schedule.recurrence?.weekdays, [.friday])
        XCTAssertEqual(schedule.openList?.friendlyId, "shl_1")
    }

    func testARecurringListIsSentWholeWithTheTemplateWithoutBlankLines() async throws {
        let transport = StubTransport([
            (201, #"{"data":{"friendly_id":"sls_1","name":"Market","next_list_content":"template_and_pending","closes_automatically":true,"waits_for_close":false,"template_items":["Milk","Bread"],"recurrence":null,"open_list":null}}"#),
        ])
        let client = APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|t"), transport: transport)
        let input = ShoppingListScheduleInput(
            name: "Market",
            recurrence: RecurrenceInput(frequency: .weekly, timeOfDay: "18:00", weekdays: [.friday]),
            nextListContent: .templateAndPending,
            closesAutomatically: true,
            waitsForClose: false,
            templateItems: ShoppingListScheduleInput.templateItems(fromLines: " Milk \n\n  \nBread\n")
        )

        _ = try await client.send(API.createShoppingSchedule(input))

        XCTAssertEqual(transport.lastRequest?.httpMethod, "POST")
        XCTAssertEqual(transport.lastRequest?.url?.path(), "/api/v1/shopping/schedules")

        let body = try XCTUnwrap(transport.lastRequest?.httpBody)
        let sent = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])

        XCTAssertEqual(sent["next_list_content"] as? String, "template_and_pending")
        XCTAssertEqual(sent["closes_automatically"] as? Bool, true)
        XCTAssertEqual(sent["template_items"] as? [String], ["Milk", "Bread"])
        XCTAssertEqual((sent["recurrence"] as? [String: Any])?["time_of_day"] as? String, "18:00")
    }
}
