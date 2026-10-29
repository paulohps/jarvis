import SwiftUI
import UIKit

/// The keyboard extension's entry point: hosts KeyboardView at a fixed
/// height and connects it to the text field (read what was typed, replace it
/// with the draft) and to the next keyboard.
final class KeyboardViewController: UIInputViewController {
    private static let height: CGFloat = 300

    private lazy var model = KeyboardModel(
        client: KeyboardModel.makeClient(),
        hasFullAccess: { [weak self] in self?.hasFullAccess ?? false }
    )

    override func viewDidLoad() {
        super.viewDidLoad()

        let host = UIHostingController(rootView: KeyboardView(
            model: model,
            typedText: { [weak self] in self?.textDocumentProxy.documentContextBeforeInput },
            insert: { [weak self] text, typed in self?.insert(text, replacing: typed) },
            nextKeyboard: { [weak self] in self?.advanceToNextInputMode() }
        ))

        addChild(host)
        host.view.translatesAutoresizingMaskIntoConstraints = false
        host.view.backgroundColor = .clear
        view.addSubview(host.view)

        NSLayoutConstraint.activate([
            host.view.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            host.view.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            host.view.topAnchor.constraint(equalTo: view.topAnchor),
            host.view.bottomAnchor.constraint(equalTo: view.bottomAnchor),
            view.heightAnchor.constraint(equalToConstant: Self.height),
        ])

        host.didMove(toParent: self)
    }

    /// Puts the draft in the field; the instruction typed before it is removed
    /// first, when it is still right before the cursor.
    private func insert(_ text: String, replacing typed: String?) {
        let proxy = textDocumentProxy

        if let typed, let before = proxy.documentContextBeforeInput,
           before.trimmingCharacters(in: .whitespacesAndNewlines).hasSuffix(typed) {
            for _ in 0 ..< before.count {
                proxy.deleteBackward()
            }
        }

        proxy.insertText(text)
    }
}
