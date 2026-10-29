import Localization
import Networking
import SwiftUI

/// What the chat browser shows.
enum WhatsappChatBrowserTab: String, CaseIterable, Identifiable {
    case search
    case starred
    case media
    case documents
    case links

    var id: String { rawValue }

    var title: String {
        switch self {
        case .search: "Search"
        case .starred: "Starred"
        case .media: "Media"
        case .documents: "Docs"
        case .links: "Links"
        }
    }

    var galleryKind: WhatsappGalleryKind? {
        switch self {
        case .media: .media
        case .documents: .documents
        case .links: .links
        case .search, .starred: nil
        }
    }
}

/// Searching a chat, its starred messages and its gallery (photos and
/// videos in a grid, documents, links), as WhatsApp's contact info; a tap
/// on any of them goes to the message in the chat (`onPick`).
struct WhatsappChatBrowser: View {
    let chatId: String
    let onPick: (String) -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var tab: WhatsappChatBrowserTab
    @State private var query = ""
    @State private var results: [WhatsappConversationMessage]?
    @State private var hasMore = false
    @State private var isLoadingMore = false
    @State private var failure: APIError?

    private let columns = [GridItem(.adaptive(minimum: 96), spacing: 2)]

    init(chatId: String, tab: WhatsappChatBrowserTab = .search, onPick: @escaping (String) -> Void) {
        self.chatId = chatId
        self.onPick = onPick
        _tab = State(initialValue: tab)
    }

    var body: some View {
        VStack(spacing: 0) {
            Picker(selection: $tab) {
                ForEach(WhatsappChatBrowserTab.allCases) { tab in
                    Text(verbatim: language.localized(tab.title)).tag(tab)
                }
            } label: {
                EmptyView()
            }
            .pickerStyle(.segmented)
            .padding(.horizontal)
            .padding(.vertical, 8)
            .accessibilityIdentifier("whatsapp.browser.tabs")

            if tab == .search {
                TextField(language.localized("Search messages"), text: $query)
                    .textFieldStyle(.roundedBorder)
                    .autocorrectionDisabled()
                    .padding(.horizontal)
                    .padding(.bottom, 8)
                    .accessibilityIdentifier("whatsapp.browser.query")
            }

            content
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .navigationTitle(language.localized("Search in chat"))
        .navigationBarTitleDisplayMode(.inline)
        .task(id: "\(tab.rawValue)|\(query)") {
            await load()
        }
    }

    @ViewBuilder
    private var content: some View {
        if let failure, results == nil {
            ContentUnavailableView {
                Label(failure.userMessage(in: language), systemImage: "wifi.exclamationmark")
            } actions: {
                Button(language.localized("Try again")) {
                    Task { await load() }
                }
                .buttonStyle(.glass)
            }
        } else if tab == .search, query.trimmingCharacters(in: .whitespaces).count < 2 {
            ContentUnavailableView(
                language.localized("Search messages"),
                systemImage: "magnifyingglass",
                description: language.text("Type at least two letters.")
            )
        } else if let results {
            if results.isEmpty {
                ContentUnavailableView(language.localized(emptyTitle), systemImage: emptyIcon)
            } else if tab == .media {
                ScrollView {
                    LazyVGrid(columns: columns, spacing: 2) {
                        ForEach(results) { message in
                            Button {
                                onPick(message.friendlyId)
                            } label: {
                                WhatsappGalleryTile(message: message)
                            }
                            .buttonStyle(.plain)
                            .accessibilityIdentifier("whatsapp.browser.media")
                        }
                    }
                    moreButton
                }
            } else {
                List {
                    ForEach(results) { message in
                        Button {
                            onPick(message.friendlyId)
                        } label: {
                            WhatsappBrowserRow(message: message, tab: tab)
                        }
                        .buttonStyle(.plain)
                        .accessibilityIdentifier("whatsapp.browser.result")
                    }

                    moreButton
                        .listRowBackground(Color.clear)
                }
                .listStyle(.plain)
            }
        } else {
            ProgressView()
        }
    }

    @ViewBuilder
    private var moreButton: some View {
        if hasMore {
            Button {
                Task { await loadMore() }
            } label: {
                if isLoadingMore {
                    ProgressView()
                } else {
                    Text(verbatim: language.localized("Load more"))
                }
            }
            .frame(maxWidth: .infinity)
            .padding()
        }
    }

    private var emptyTitle: String {
        switch tab {
        case .search: "No messages found"
        case .starred: "No starred messages"
        case .media: "No photos or videos"
        case .documents: "No documents"
        case .links: "No links"
        }
    }

    private var emptyIcon: String {
        switch tab {
        case .search: "magnifyingglass"
        case .starred: "star"
        case .media: "photo.on.rectangle"
        case .documents: "doc"
        case .links: "link"
        }
    }

    private func load() async {
        results = nil
        failure = nil
        hasMore = false

        do throws(APIError) {
            switch tab {
            case .search:
                let trimmed = query.trimmingCharacters(in: .whitespaces)

                guard trimmed.count >= 2 else {
                    return
                }

                // Waits for the user to stop typing before asking the server.
                try? await Task.sleep(for: .milliseconds(350))

                guard !Task.isCancelled else {
                    return
                }

                results = try await model.client.send(API.searchWhatsappConversation(chatId: chatId, query: trimmed)).data
            case .starred:
                results = try await model.client.send(API.whatsappStarredMessages(chatId: chatId)).data
            case .media, .documents, .links:
                guard let kind = tab.galleryKind else {
                    return
                }

                let page = try await model.client.send(API.whatsappGallery(chatId: chatId, kind: kind))
                results = page.data
                hasMore = page.meta.hasMore
            }
        } catch .unauthenticated {
            return
        } catch {
            if !Task.isCancelled {
                failure = error
            }
        }
    }

    private func loadMore() async {
        guard let kind = tab.galleryKind, let last = results?.last, !isLoadingMore else {
            return
        }

        isLoadingMore = true
        defer { isLoadingMore = false }

        if let page = try? await model.client.send(API.whatsappGallery(chatId: chatId, kind: kind, before: last.friendlyId)) {
            let known = Set((results ?? []).map(\.friendlyId))
            results = (results ?? []) + page.data.filter { !known.contains($0.friendlyId) }
            hasMore = page.meta.hasMore
        }
    }
}

/// A photo or video of the gallery: the picture (WhatsApp's tiny preview
/// while it loads), a video marked.
private struct WhatsappGalleryTile: View {
    let message: WhatsappConversationMessage

    @Environment(\.appLanguage) private var language

    var body: some View {
        Color.secondary.opacity(0.15)
            .aspectRatio(1, contentMode: .fit)
            .overlay {
                if message.media?.kind == .image {
                    AsyncImage(url: message.media?.url) { phase in
                        if case .success(let image) = phase {
                            image.resizable().scaledToFill()
                        } else {
                            thumbnail
                        }
                    }
                } else {
                    thumbnail
                }
            }
            .overlay(alignment: .bottomLeading) {
                if message.media?.kind == .video {
                    Image(systemName: "video.fill")
                        .font(.caption)
                        .foregroundStyle(.white)
                        .shadow(radius: 2)
                        .padding(5)
                }
            }
            .clipped()
            .contentShape(.rect)
            .accessibilityLabel(language.text(message.media?.kind == .video ? "Video" : "Photo"))
    }

    @ViewBuilder
    private var thumbnail: some View {
        if let data = message.media?.thumbnailData, let image = UIImage(data: data) {
            Image(uiImage: image)
                .resizable()
                .scaledToFill()
        }
    }
}

/// A search result, a starred message, a document or a link: who sent it,
/// when, and its text or file name.
private struct WhatsappBrowserRow: View {
    let message: WhatsappConversationMessage
    let tab: WhatsappChatBrowserTab

    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon)
                .foregroundStyle(WhatsappPalette.meta)
                .frame(width: 22)

            VStack(alignment: .leading, spacing: 2) {
                HStack {
                    Text(verbatim: message.fromMe ? language.localized("You") : (message.sender ?? language.localized("Contact")))
                        .font(.subheadline.weight(.semibold))
                        .lineLimit(1)
                    Spacer()
                    Text(verbatim: WhatsappDates.listTime(message.sentAt, in: language))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                Text(verbatim: WhatsappMessageText.preview(of: message, in: language))
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(3)
            }
        }
        .contentShape(.rect)
    }

    private var icon: String {
        switch tab {
        case .documents: "doc"
        case .links: "link"
        case .starred: "star.fill"
        case .search, .media: "text.bubble"
        }
    }
}
