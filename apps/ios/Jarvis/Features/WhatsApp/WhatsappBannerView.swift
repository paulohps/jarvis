import Localization
import Navigation
import Networking
import SwiftUI

/// The banner of a WhatsApp message while the app is open (`WhatsappLive`),
/// at the top of the screen as WhatsApp shows one: the chat's picture, its
/// name and what came. A tap opens the chat; swiping it up dismisses it.
struct WhatsappBannerOverlay: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        VStack {
            if let banner = model.whatsapp.banner {
                WhatsappBannerView(chat: banner.chat)
                    .id(banner.id)
                    .transition(.move(edge: .top).combined(with: .opacity))
                    .onTapGesture {
                        model.whatsapp.dismissBanner()
                        _ = model.router.open(.whatsappChat(id: banner.chat.friendlyId), in: model.tabs)
                    }
                    .gesture(DragGesture(minimumDistance: 10).onEnded { value in
                        if value.translation.height < 0 {
                            model.whatsapp.dismissBanner()
                        }
                    })
            }

            Spacer()
        }
        .padding(.horizontal, 12)
        .animation(.spring(duration: 0.35), value: model.whatsapp.banner)
    }
}

private struct WhatsappBannerView: View {
    let chat: WhatsappConversation

    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack(spacing: 12) {
            WhatsappChatAvatar(chat: chat, size: 40)

            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: chat.name)
                    .font(.subheadline.weight(.semibold))
                    .lineLimit(1)

                Text(verbatim: excerpt)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(2)
            }

            Spacer(minLength: 0)
        }
        .padding(12)
        .frame(maxWidth: 520)
        .glassEffect(.regular, in: .rect(cornerRadius: 22))
        .shadow(color: .black.opacity(0.12), radius: 12, y: 4)
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(.isButton)
        .accessibilityIdentifier("whatsapp.banner")
    }

    private var excerpt: String {
        guard let message = chat.lastMessage else {
            return ""
        }

        let text = WhatsappMessageText.preview(of: message, in: language)

        if chat.isGroup, let sender = message.sender {
            return "\(sender): \(text)"
        }

        return text
    }
}

/// What a chat's presence says, in the user's language.
enum WhatsappPresenceText {
    static func text(for status: WhatsappPresenceStatus, in language: AppLanguage, calendar: Calendar = .current) -> String {
        switch status {
        case .typing(let who?):
            language.localized("%@ is typing…", who)
        case .typing:
            language.localized("typing…")
        case .recording(let who?):
            language.localized("%@ is recording audio…", who)
        case .recording:
            language.localized("recording audio…")
        case .online:
            language.localized("online")
        case .lastSeen(let date):
            language.localized("last seen %@", lastSeen(date, in: language, calendar: calendar))
        }
    }

    /// The time for today, otherwise the day and the time.
    private static func lastSeen(_ date: Date, in language: AppLanguage, calendar: Calendar) -> String {
        let time = date.formatted(Date.FormatStyle(date: .omitted, time: .shortened).locale(language.locale))

        if calendar.isDateInToday(date) {
            return time
        }

        return "\(WhatsappDates.listTime(date, in: language, calendar: calendar)) \(time)"
    }
}
