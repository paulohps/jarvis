import XCTest
@testable import Navigation
import Networking

final class TabBuilderTests: XCTestCase {
    private func module(_ slug: String, mobile: Bool = true) -> UserModule {
        UserModule(slug: slug, name: slug.capitalized, isAvailableOnMobile: mobile)
    }

    func testEveryMobileModuleGivesItsTabBeforeNotificationsAndSettings() {
        let tabs = TabBuilder.tabs(for: [module("whatsapp"), module("reminders"), module("shopping")])

        XCTAssertEqual(tabs, [.shopping, .reminders, .whatsapp, .notifications, .settings])
    }

    func testOnlyTheModulesOfTheUserHaveTabs() {
        XCTAssertEqual(TabBuilder.tabs(for: [module("reminders")]), [.reminders, .notifications, .settings])
        XCTAssertEqual(TabBuilder.tabs(for: []), [.notifications, .settings])
    }

    func testInvoicesAndAdminNeverHaveTabs() {
        // The super admin's /me: every active module, invoices included.
        let tabs = TabBuilder.tabs(for: [
            module("invoices", mobile: false),
            module("shopping"),
            module("admin", mobile: false),
        ])

        XCTAssertEqual(tabs, [.shopping, .notifications, .settings])
    }

    func testAModuleNotAvailableOnMobileHasNoTab() {
        XCTAssertEqual(TabBuilder.tabs(for: [module("shopping", mobile: false)]), [.notifications, .settings])
    }

    func testAnUnknownModuleIsIgnored() {
        XCTAssertEqual(TabBuilder.tabs(for: [module("finance"), module("whatsapp")]), [.whatsapp, .notifications, .settings])
    }

    func testAModuleSwitchedOffDisappearsOnTheNextRefresh() {
        let before = TabBuilder.tabs(for: [module("shopping"), module("reminders")])
        let after = TabBuilder.tabs(for: [module("reminders")])

        XCTAssertEqual(before, [.shopping, .reminders, .notifications, .settings])
        XCTAssertEqual(after, [.reminders, .notifications, .settings])
        XCTAssertEqual(TabBuilder.selection(keeping: .shopping, in: after), .reminders)
        XCTAssertEqual(TabBuilder.selection(keeping: .settings, in: after), .settings)
    }
}
