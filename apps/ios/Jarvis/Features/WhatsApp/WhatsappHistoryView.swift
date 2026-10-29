import Localization
import Networking
import SwiftUI

/// What went out from the user's numbers, newest first (US-7.5): to whom,
/// the text as sent, from which number, and whether it was sent or failed.
struct WhatsappHistoryView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var deliveries: [WhatsappDelivery]?
    @State private var page = 1
    @State private var hasMorePages = false
    @State private var loadFailure: APIError?

    var body: some View {
        Group {
            if let deliveries {
                if deliveries.isEmpty {
                    ContentUnavailableView(language.localized("Nothing sent yet"), systemImage: "paperplane")
                } else {
                    List {
                        ForEach(Array(deliveries.enumerated()), id: \.offset) { _, delivery in
                            WhatsappDeliveryRow(delivery: delivery)
                        }

                        if hasMorePages {
                            ProgressView()
                                .frame(maxWidth: .infinity)
                                .task {
                                    await load(page: page + 1)
                                }
                        }
                    }
                }
            } else if let loadFailure {
                if case .forbidden = loadFailure {
                    NoAccessView()
                } else {
                    ContentUnavailableView {
                        Label(loadFailure.userMessage(in: language), systemImage: "wifi.exclamationmark")
                    } actions: {
                        Button(language.localized("Try again")) {
                            Task { await load(page: 1) }
                        }
                        .buttonStyle(.glass)
                    }
                }
            } else {
                ProgressView(language.localized("Loading…"))
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .task {
            await load(page: 1)
        }
        .refreshable {
            await load(page: 1)
        }
    }

    private func load(page: Int) async {
        do throws(APIError) {
            let result = try await model.client.send(API.whatsappDeliveries(page: page))

            deliveries = page == 1 ? result.data : (deliveries ?? []) + result.data
            self.page = result.meta.currentPage
            hasMorePages = result.meta.hasMorePages
            loadFailure = nil
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            hasMorePages = false

            if deliveries == nil {
                loadFailure = error
            }
        }
    }
}

private struct WhatsappDeliveryRow: View {
    let delivery: WhatsappDelivery

    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            statusIcon
                .font(.title3)
                .frame(width: 28)

            VStack(alignment: .leading, spacing: 4) {
                HStack(alignment: .firstTextBaseline) {
                    Text(verbatim: delivery.target.name)
                        .font(.headline)
                        .lineLimit(1)

                    Spacer(minLength: 8)

                    if let date = delivery.sentAt ?? delivery.scheduledFor ?? delivery.createdAt {
                        Text(date, format: .dateTime.day().month().hour().minute())
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }

                Text(verbatim: delivery.body)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(3)

                if delivery.status == .failed, let errorMessage = delivery.errorMessage, !errorMessage.isEmpty {
                    Text(verbatim: errorMessage)
                        .font(.caption)
                        .foregroundStyle(.red)
                }

                Text(verbatim: "\(delivery.account.nickname) · \(language.localized(sourceKey))")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
        }
        .padding(.vertical, 2)
        .accessibilityElement(children: .combine)
    }

    @ViewBuilder
    private var statusIcon: some View {
        switch delivery.status {
        case .pending:
            Image(systemName: "clock")
                .foregroundStyle(.orange)
                .accessibilityLabel(Text(verbatim: language.localized("Pending")))
        case .sent:
            Image(systemName: "checkmark.circle.fill")
                .foregroundStyle(.green)
                .accessibilityLabel(Text(verbatim: language.localized("Sent")))
        case .failed:
            Image(systemName: "exclamationmark.circle.fill")
                .foregroundStyle(.red)
                .accessibilityLabel(Text(verbatim: language.localized("Failed")))
        }
    }

    private var sourceKey: String {
        switch delivery.source {
        case "scheduled": "Scheduled"
        case "manual": "Sent now"
        case "notification": "Notification"
        case "voice": "Voice"
        default: delivery.source
        }
    }
}
