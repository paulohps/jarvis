import Localization
import Networking
import SwiftUI

/// The emojis WhatsApp offers first on a long press, and the ones people use
/// most by group (the same as the web's picker), with the ones picked lately
/// first, kept on this device.
enum WhatsappEmojis {
    static let quick = ["👍", "❤️", "😂", "😮", "😢", "🙏"]

    struct Group: Identifiable {
        let key: String
        let icon: String
        let emojis: [String]

        var id: String { key }
    }

    static let groups: [Group] = [
        Group(key: "Smileys", icon: "😀", emojis: "😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😙 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 😮‍💨 🤥 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🥵 🥶 🥴 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 🥹 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠 🤬 😈 👿 💀 💩 🤡 👻 👽 🤖 😺 😸 😹 😻 😼 😽 🙀 😿 😾"),
        Group(key: "Gestures", icon: "👍", emojis: "👍 👎 👌 🤌 🤏 ✌️ 🤞 🫰 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 🫡 👏 🙌 🫶 👐 🤲 🤝 🙏 ✍️ 💪 🦾 🧠 👀 👁️ 👅 👄 💋 🫂 👶 🧒 👦 👧 🧑 👨 👩 🧓 👴 👵 🙋 🙆 🙅 🤷 🤦 💁 🙇 🧑‍💻 👨‍👩‍👧 👫"),
        Group(key: "Hearts and symbols", icon: "❤️", emojis: "❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❤️‍🔥 ❤️‍🩹 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ♥️ 💯 💢 💥 💫 💦 💨 🕳️ 💬 🗨️ 💭 💤 ✨ ⭐ 🌟 🔥 🎉 🎊 🎈 🎁 🏆 🥇 ✅ ❌ ❗ ❓ ⚠️ 🚫 🔔 📌 📍"),
        Group(key: "Animals and nature", icon: "🐶", emojis: "🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐔 🐧 🐦 🐤 🦆 🦉 🐴 🦄 🐝 🦋 🐌 🐞 🐢 🐍 🐙 🐠 🐬 🐳 🦈 🐊 🐘 🦒 🐕 🐈 🌵 🌲 🌳 🌴 🌱 🌿 🍀 🍁 🍂 🌷 🌹 🌺 🌸 🌼 🌻 🌞 🌝 🌙 ⭐ ☀️ ⛅ 🌧️ ⛈️ ❄️ ☃️ 🌈 🌊"),
        Group(key: "Food and drink", icon: "🍔", emojis: "🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🫐 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🥑 🥦 🥕 🌽 🌶️ 🥔 🍞 🧀 🥚 🍳 🥓 🥩 🍗 🍖 🌭 🍔 🍟 🍕 🥪 🌮 🌯 🥗 🍝 🍜 🍣 🍱 🍤 🍚 🍦 🍰 🎂 🧁 🍫 🍬 🍭 🍩 🍪 ☕ 🍵 🥤 🧃 🍺 🍻 🍷 🥂 🍾 🍹 🧉"),
        Group(key: "Activities and places", icon: "⚽", emojis: "⚽ 🏀 🏈 ⚾ 🎾 🏐 🏉 🎱 🏓 🏸 🥊 🥋 ⛳ 🎣 🏊 🏄 🚴 🏃 🧘 🏋️ 🤸 🎮 🎲 🧩 🎯 🎳 🎸 🎹 🥁 🎤 🎧 🎬 🎨 📚 ✈️ 🚗 🚕 🚌 🏍️ 🚲 🚀 ⛵ 🏠 🏡 🏢 🏥 🏫 ⛪ 🏖️ 🏝️ 🗺️ 🎡 🎢"),
        Group(key: "Objects", icon: "💡", emojis: "⌚ 📱 💻 ⌨️ 🖥️ 🖨️ 📷 📸 📹 🎥 📞 ☎️ 📺 📻 ⏰ ⏳ 🔋 🔌 💡 🔦 🕯️ 💸 💵 💰 💳 💎 🔧 🔨 🛠️ 🔑 🗝️ 🔒 🔓 🚪 🛏️ 🛋️ 🚿 🧹 🧺 🧻 🛒 🎒 👓 👔 👕 👖 👗 👟 👠 💍 💄 📦 ✉️ 📧 📝 📅 📆 📎 ✂️ 🗑️ 💊 💉 🩺"),
    ]

    private static let recentKey = "whatsapp.emoji.recent"
    private static let recentLimit = 24

    /// The emojis picked lately on this device, the latest first.
    static var recent: [String] {
        UserDefaults.standard.stringArray(forKey: recentKey) ?? []
    }

    static func used(_ emoji: String) {
        let updated = [emoji] + recent.filter { $0 != emoji }
        UserDefaults.standard.set(Array(updated.prefix(recentLimit)), forKey: recentKey)
    }
}

private extension WhatsappEmojis.Group {
    init(key: String, icon: String, emojis: String) {
        self.init(key: key, icon: icon, emojis: emojis.split(separator: " ").map(String.init))
    }
}

/// Every emoji to react with, by group, the recent ones first; the one the
/// number reacted with is marked, and picking it again takes it back.
struct WhatsappEmojiPickerSheet: View {
    let current: String?
    let onPick: (String) -> Void

    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var recent = WhatsappEmojis.recent
    @State private var group: String = WhatsappEmojis.recent.isEmpty ? WhatsappEmojis.groups[0].key : "Recent"

    private let columns = [GridItem(.adaptive(minimum: 44), spacing: 4)]

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 4) {
                        if !recent.isEmpty {
                            tab("Recent", icon: nil)
                        }

                        ForEach(WhatsappEmojis.groups) { group in
                            tab(group.key, icon: group.icon)
                        }
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 6)
                }

                Divider()

                ScrollView {
                    LazyVGrid(columns: columns, spacing: 4) {
                        ForEach(Array(shown.enumerated()), id: \.offset) { _, emoji in
                            Button {
                                WhatsappEmojis.used(emoji)
                                onPick(emoji)
                                dismiss()
                            } label: {
                                Text(verbatim: emoji)
                                    .font(.system(size: 30))
                                    .frame(width: 44, height: 44)
                                    .background(emoji == current ? WhatsappPalette.send.opacity(0.2) : Color.clear, in: .rect(cornerRadius: 10))
                            }
                            .buttonStyle(.plain)
                            .accessibilityLabel(Text(verbatim: emoji))
                            .accessibilityAddTraits(emoji == current ? .isSelected : [])
                        }
                    }
                    .padding(12)
                }
                .accessibilityIdentifier("whatsapp.emoji.grid")
            }
            .navigationTitle(language.localized("React"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
            }
        }
        .presentationDetents([.medium, .large])
    }

    private var shown: [String] {
        group == "Recent" ? recent : (WhatsappEmojis.groups.first { $0.key == group }?.emojis ?? [])
    }

    private func tab(_ key: String, icon: String?) -> some View {
        Button {
            group = key
        } label: {
            Group {
                if let icon {
                    Text(verbatim: icon)
                        .font(.title2)
                } else {
                    Image(systemName: "clock")
                        .font(.title3)
                        .foregroundStyle(WhatsappPalette.meta)
                }
            }
            .frame(width: 40, height: 36)
            .background(group == key ? WhatsappPalette.send.opacity(0.18) : Color.clear, in: .rect(cornerRadius: 8))
        }
        .buttonStyle(.plain)
        .accessibilityLabel(language.text(key))
        .accessibilityAddTraits(group == key ? .isSelected : [])
    }
}

/// The reactions under a bubble, as WhatsApp shows them: each emoji with how
/// many when more than one, the number's own marked; a tap reacts with it,
/// or takes the number's own back.
struct WhatsappReactionChips: View {
    let reactions: [WhatsappConversationMessage.Reaction]
    let onTap: (WhatsappConversationMessage.Reaction) -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack(spacing: 4) {
            ForEach(reactions) { reaction in
                Button {
                    onTap(reaction)
                } label: {
                    HStack(spacing: 2) {
                        Text(verbatim: reaction.emoji)
                            .font(.system(size: 15))

                        if reaction.count > 1 {
                            Text(verbatim: "\(reaction.count)")
                                .font(.caption2.weight(.medium))
                                .foregroundStyle(WhatsappPalette.meta)
                        }
                    }
                    .padding(.horizontal, 6)
                    .padding(.vertical, 2)
                    .background(reaction.mine ? WhatsappPalette.send.opacity(0.25) : WhatsappPalette.incoming, in: .capsule)
                    .overlay(Capsule().strokeBorder(WhatsappPalette.wallpaper, lineWidth: 1.5))
                }
                .buttonStyle(.plain)
                .accessibilityLabel(Text(verbatim: reaction.mine ? language.localized("%@, your reaction. Tap to remove", reaction.emoji) : "\(reaction.emoji) \(reaction.count)"))
                .accessibilityIdentifier("whatsapp.conversation.reaction")
            }
        }
    }
}
