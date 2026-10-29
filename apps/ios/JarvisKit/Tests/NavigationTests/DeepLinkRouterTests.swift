import Foundation
import XCTest
@testable import Navigation

final class DeepLinkRouterTests: XCTestCase {
    private let router = DeepLinkRouter(webBaseURL: URL(string: "https://jarvis.example.com"))

    private func route(_ link: String) -> AppRoute? {
        router.route(for: URL(string: link)!)
    }

    func testAReminderLinkOpensTheReminder() {
        XCTAssertEqual(route("jarvis://reminders/rem_8Kq2Zp0aLx9cVb3nM4tY7wQe"), .reminder(id: "rem_8Kq2Zp0aLx9cVb3nM4tY7wQe"))
        XCTAssertEqual(route("jarvis://reminders/remo_8Kq2Zp0aLx9cVb3nM4tY7wQe"), .reminder(id: "remo_8Kq2Zp0aLx9cVb3nM4tY7wQe"))
        XCTAssertEqual(route("jarvis://reminder-occurrences/remo_1"), .reminder(id: "remo_1"))
        XCTAssertEqual(route("jarvis://reminders/rem_1")?.tab, .reminders)
    }

    func testModuleLinksOpenTheirTabs() {
        XCTAssertEqual(route("jarvis://reminders"), .tab(.reminders))
        XCTAssertEqual(route("jarvis://shopping"), .tab(.shopping))
        XCTAssertEqual(route("jarvis://whatsapp"), .tab(.whatsapp))
        XCTAssertEqual(route("jarvis://whatsapp/history"), .tab(.whatsapp))
        XCTAssertEqual(route("jarvis://notifications"), .tab(.notifications))
        XCTAssertEqual(route("jarvis://settings"), .tab(.settings))
        XCTAssertEqual(route("jarvis://"), .tab(.notifications))
    }

    func testAShoppingListLinkOpensTheList() {
        XCTAssertEqual(route("jarvis://shopping/lists/shl_1"), .shoppingList(id: "shl_1"))
        XCTAssertEqual(route("jarvis://shopping/shl_1"), .shoppingList(id: "shl_1"))
        XCTAssertEqual(route("jarvis://shopping/lists"), .tab(.shopping))
    }

    func testAWhatsappChatLinkOpensTheChat() {
        // What the push of a new WhatsApp message carries.
        XCTAssertEqual(route("https://jarvis.example.com/whatsapp/chat?account=wacc_1&chat=wtgt_8Kq2"), .whatsappChat(id: "wtgt_8Kq2"))
        XCTAssertEqual(route("jarvis://whatsapp/chat?chat=wtgt_1"), .whatsappChat(id: "wtgt_1"))
        XCTAssertEqual(route("jarvis://whatsapp/chat?chat=wtgt_1")?.tab, .whatsapp)
        XCTAssertEqual(route("https://jarvis.example.com/whatsapp/chat"), .tab(.whatsapp))
        XCTAssertEqual(route("jarvis://whatsapp/chat?chat="), .tab(.whatsapp))
    }

    func testTheServersWebLinksOpenInTheApp() {
        // What the notification actions and the push `deep_link` carry today.
        XCTAssertEqual(route("https://jarvis.example.com/reminders"), .tab(.reminders))
        XCTAssertEqual(route("https://jarvis.example.com/shopping/lists/shl_1"), .shoppingList(id: "shl_1"))
        XCTAssertEqual(route("https://jarvis.example.com/whatsapp/history"), .tab(.whatsapp))
        XCTAssertEqual(route("https://JARVIS.example.com/notifications/9b1c/open"), .tab(.notifications))
    }

    func testWebOnlyPagesOpenInTheBrowser() {
        let invoice = URL(string: "https://jarvis.example.com/invoices/history/inv_1")!

        XCTAssertEqual(router.route(for: invoice), .web(invoice))
        XCTAssertNil(route("jarvis://invoices/inv_1"))
        XCTAssertNil(route("jarvis://admin"))
    }

    func testForeignAndUnknownLinksAreIgnored() {
        XCTAssertNil(route("https://evil.example.org/reminders/rem_1"))
        XCTAssertNil(route("mailto:ana@example.com"))
        XCTAssertNil(route("jarvis://unknown/thing"))
        XCTAssertNil(route("https://jarvis.example.com/"))
    }
}
