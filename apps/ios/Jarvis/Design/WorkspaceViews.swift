import Localization
import Networking
import SwiftUI

/// The dot of a workspace's color, next to a shared record (a list, a
/// reminder) when the user is in two or more workspaces with its module.
struct WorkspaceDot: View {
    let hex: String
    var size: CGFloat = 10

    /// What VoiceOver reads (the workspace's name); hidden without it.
    var accessibilityName: String?

    var body: some View {
        if let accessibilityName {
            dot.accessibilityLabel(Text(verbatim: accessibilityName))
        } else {
            dot.accessibilityHidden(true)
        }
    }

    private var dot: some View {
        Circle()
            .fill(Color(hex: hex) ?? .secondary)
            .frame(width: size, height: size)
    }
}

/// A workspace's dot and name, as a small badge.
struct WorkspaceBadge: View {
    let workspace: WorkspaceSummary

    var body: some View {
        HStack(spacing: 4) {
            WorkspaceDot(hex: workspace.colorHex, size: 8)
            Text(verbatim: workspace.name)
                .lineLimit(1)
        }
        .font(.caption)
        .foregroundStyle(.secondary)
    }
}

/// Where a new list, recurring list or reminder goes, or where an edited one
/// moves to: one row per workspace of the user with the module, with its
/// color. The forms show it only when there are two or more.
struct WorkspacePickerSection: View {
    let workspaces: [UserWorkspace]
    @Binding var selection: String?

    @Environment(\.appLanguage) private var language

    var body: some View {
        Section {
            Picker(selection: $selection) {
                ForEach(workspaces) { workspace in
                    Label {
                        Text(verbatim: workspace.name)
                    } icon: {
                        WorkspaceDot(hex: workspace.colorHex, size: 12)
                    }
                    .tag(String?.some(workspace.id))
                }
            } label: {
                language.text("Workspace")
            }
            .pickerStyle(.inline)
            .labelsHidden()
            .accessibilityIdentifier("workspace.picker")
        } header: {
            language.text("Workspace")
        }
    }
}

extension User {
    /// The workspaces a form offers for the module: none (no picker) when the
    /// user has the module in one workspace only.
    func workspacePickerOptions(forModule slug: String) -> [UserWorkspace] {
        showsWorkspaces(forModule: slug) ? workspaces(withModule: slug) : []
    }
}

/// The palette of `GET /workspace-colors`: a circle per color, the picked
/// one checked. With `allowsWorkspaceColor`, a first row picks no color of
/// one's own (`nil`): the workspace's.
struct WorkspaceColorPalette: View {
    let colors: [WorkspaceColorOption]
    @Binding var selection: String?
    var allowsWorkspaceColor = false
    var workspaceColorHex: String?

    @Environment(\.appLanguage) private var language

    var body: some View {
        if allowsWorkspaceColor {
            Button {
                selection = nil
            } label: {
                HStack {
                    if let workspaceColorHex {
                        WorkspaceDot(hex: workspaceColorHex, size: 14)
                    }

                    language.text("Use the workspace's color")
                        .foregroundStyle(.primary)

                    Spacer()

                    if selection == nil {
                        Image(systemName: "checkmark")
                            .foregroundStyle(.tint)
                    }
                }
            }
            .accessibilityIdentifier("workspace.color.workspace")
        }

        LazyVGrid(columns: [GridItem(.adaptive(minimum: 40), spacing: 12)], spacing: 12) {
            ForEach(colors) { color in
                Button {
                    selection = color.name
                } label: {
                    ZStack {
                        Circle()
                            .fill(Color(hex: color.hex) ?? .secondary)
                            .frame(width: 34, height: 34)

                        if selection == color.name {
                            Image(systemName: "checkmark")
                                .font(.footnote.weight(.bold))
                                .foregroundStyle(.white)
                        }
                    }
                    .frame(minWidth: 44, minHeight: 44)
                    .contentShape(.rect)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(Text(verbatim: language.localized(color.name.capitalized)))
                .accessibilityAddTraits(selection == color.name ? .isSelected : [])
            }
        }
        .padding(.vertical, 4)
    }
}

/// The module slugs of `/me` whose records live in a workspace.
enum ModuleSlug {
    static let shopping = "shopping"
    static let reminders = "reminders"
}

extension User {
    /// Whether an agenda item shows its workspace's color: a shared item
    /// (a reminder, an event, a list, an invoice) whose module the user has
    /// in two or more workspaces. Personal sources have none.
    func showsWorkspace(of entry: CalendarEntry) -> Bool {
        guard entry.workspace != nil else {
            return false
        }

        let slug: String? = switch entry.calendarSource {
        case .reminders, .events: ModuleSlug.reminders
        case .shopping: ModuleSlug.shopping
        case .invoices: "invoices"
        case .whatsapp, .google, nil: nil
        }

        return slug.map { showsWorkspaces(forModule: $0) } ?? false
    }
}
