import Localization
import Networking
import SwiftUI

/// The sizes of the hour grid.
private enum Timeline {
    static let hourHeight: CGFloat = 48
    static let labelWidth: CGFloat = 44
    static let height = hourHeight * 24

    /// The hour shown first: an hour before now on today, 08:00 otherwise.
    static func firstHour(of day: Date, calendar: Calendar) -> Int {
        calendar.isDateInToday(day) ? max(0, calendar.component(.hour, from: .now) - 1) : 8
    }

    static func offset(minutes: Int) -> CGFloat {
        CGFloat(minutes) / 60 * hourHeight
    }
}

/// The day on an hour grid, as the web's day view: the all-day items above,
/// the timed ones at their hours — side by side when they overlap — and a
/// line at the current time. A tap on an empty hour creates an item there.
struct AgendaDayView: View {
    @Bindable var store: RemindersStore
    let onOpen: (CalendarEntry) -> Void
    let onCreate: (Date) -> Void

    var body: some View {
        let day = store.selectedDay
        let entries = store.entries(on: day)
        let allDay = entries.filter(\.isAllDay)

        TimelineScroll(day: day, calendar: store.calendar, isLoading: store.entries == nil, showsNow: store.calendar.isDateInToday(day)) { width in
            ColumnBlocks(
                slots: DayTimeline.layout(entries, on: day, calendar: store.calendar),
                day: day,
                calendar: store.calendar,
                width: width,
                onOpen: onOpen,
                onCreate: onCreate
            )
        }
        .pinnedHeader {
            VStack(spacing: 0) {
                if !allDay.isEmpty {
                    VStack(spacing: 2) {
                        ForEach(allDay) { entry in
                            AllDayChip(entry: entry, onOpen: onOpen)
                        }
                    }
                    .padding(.leading, Timeline.labelWidth)
                    .padding(.horizontal, 8)
                    .padding(.bottom, 4)
                }

                Divider()
            }
        }
        .accessibilityIdentifier("agenda.day")
    }
}

/// The week from Sunday on an hour grid, as the web's week view: a column per
/// day, its all-day items on top. A tap on a day's heading opens it on the
/// day view; a tap on an empty hour creates an item there.
struct AgendaWeekView: View {
    @Bindable var store: RemindersStore
    let onOpen: (CalendarEntry) -> Void
    let onCreate: (Date) -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        let calendar = store.calendar
        let days = AgendaPeriod(.week, around: store.selectedDay, calendar: calendar).days(in: calendar)

        TimelineScroll(
            day: store.selectedDay,
            calendar: calendar,
            isLoading: store.entries == nil,
            showsNow: days.contains { calendar.isDateInToday($0) }
        ) { width in
            let columnWidth = width / 7

            HStack(spacing: 0) {
                ForEach(days, id: \.self) { day in
                    ColumnBlocks(
                        slots: DayTimeline.layout(store.entries(on: day), on: day, calendar: calendar),
                        day: day,
                        calendar: calendar,
                        width: columnWidth,
                        isCompact: true,
                        onOpen: onOpen,
                        onCreate: onCreate
                    )
                    .overlay(alignment: .leading) {
                        Rectangle().fill(Color.secondary.opacity(0.15)).frame(width: 0.5)
                    }
                }
            }
        }
        .pinnedHeader {
            VStack(spacing: 0) {
                HStack(alignment: .top, spacing: 0) {
                    Color.clear.frame(width: Timeline.labelWidth, height: 1)

                    ForEach(days, id: \.self) { day in
                        VStack(spacing: 2) {
                            Button {
                                Task { await store.open(day: day) }
                            } label: {
                                VStack(spacing: 0) {
                                    Text(verbatim: day.formatted(Date.FormatStyle(locale: language.locale, timeZone: store.timeZone).weekday(.narrow)))
                                        .font(.caption2)
                                        .foregroundStyle(.secondary)
                                    Text(verbatim: String(calendar.component(.day, from: day)))
                                        .font(.subheadline.weight(calendar.isDateInToday(day) ? .bold : .regular))
                                        .foregroundStyle(calendar.isDateInToday(day) ? Color.white : Color.primary)
                                        .frame(width: 28, height: 28)
                                        .background(Circle().fill(calendar.isDateInToday(day) ? Color.accentColor : Color.clear))
                                }
                                .frame(maxWidth: .infinity, minHeight: 44)
                                .contentShape(.rect)
                            }
                            .buttonStyle(.plain)
                            .accessibilityLabel(Text(verbatim: day.formatted(
                                Date.FormatStyle(date: .complete, time: .omitted, locale: language.locale, timeZone: store.timeZone)
                            )))

                            let allDay = store.entries(on: day).filter(\.isAllDay)

                            ForEach(allDay.prefix(2)) { entry in
                                AllDayChip(entry: entry, isCompact: true, onOpen: onOpen)
                            }

                            if allDay.count > 2 {
                                Text(verbatim: "+\(allDay.count - 2)")
                                    .font(.caption2)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .padding(.horizontal, 1)
                    }
                }
                .padding(.trailing, 4)
                .padding(.bottom, 4)

                Divider()
            }
        }
        .accessibilityIdentifier("agenda.week")
    }
}

/// The 24 hours with their lines and labels, scrolled to the hour that
/// matters, with `content` laid over the width right of the labels.
private struct TimelineScroll<Content: View>: View {
    let day: Date
    let calendar: Calendar
    let isLoading: Bool

    /// Whether today is on the grid.
    let showsNow: Bool
    @ViewBuilder let content: (CGFloat) -> Content

    @Environment(\.appLanguage) private var language

    var body: some View {
        ScrollViewReader { proxy in
            ScrollView {
                ZStack(alignment: .topLeading) {
                    VStack(spacing: 0) {
                        ForEach(0..<24, id: \.self) { hour in
                            HStack(alignment: .top, spacing: 4) {
                                Text(verbatim: label(of: hour))
                                    .font(.caption2)
                                    .foregroundStyle(.secondary)
                                    .frame(width: Timeline.labelWidth - 4, alignment: .trailing)
                                    .offset(y: -6)
                                    .opacity(hour == 0 ? 0 : 1)

                                VStack {
                                    Divider()
                                    Spacer(minLength: 0)
                                }
                            }
                            .frame(height: Timeline.hourHeight)
                            .id(hour)
                        }
                    }

                    GeometryReader { geometry in
                        content(geometry.size.width - Timeline.labelWidth)
                            .padding(.leading, Timeline.labelWidth)
                    }
                    .frame(height: Timeline.height)

                    if showsNow {
                        NowLine(calendar: calendar)
                    }

                    if isLoading {
                        ProgressView()
                            .frame(maxWidth: .infinity)
                            .padding(.top, Timeline.offset(minutes: Timeline.firstHour(of: day, calendar: calendar) * 60) + 24)
                    }
                }
                .padding(.trailing, 4)
            }
            .onAppear {
                proxy.scrollTo(Timeline.firstHour(of: day, calendar: calendar), anchor: .top)
            }
        }
    }

    /// "08:00" or "8 AM", in the app's language.
    private func label(of hour: Int) -> String {
        let date = calendar.date(bySettingHour: hour, minute: 0, second: 0, of: day) ?? day

        return date.formatted(Date.FormatStyle(locale: language.locale, timeZone: calendar.timeZone).hour())
    }
}

/// The red line at the current time, refreshed every minute.
private struct NowLine: View {
    let calendar: Calendar

    var body: some View {
        TimelineView(.everyMinute) { context in
            let minutes = calendar.component(.hour, from: context.date) * 60 + calendar.component(.minute, from: context.date)

            HStack(spacing: 0) {
                Circle()
                    .fill(Color.red)
                    .frame(width: 8, height: 8)
                Rectangle()
                    .fill(Color.red)
                    .frame(height: 1.5)
            }
            .padding(.leading, Timeline.labelWidth - 4)
            .offset(y: Timeline.offset(minutes: minutes) - 4)
            .allowsHitTesting(false)
            .accessibilityHidden(true)
        }
    }
}

/// One day's column: its empty hours, which create an item when tapped, and
/// its timed items at their place.
private struct ColumnBlocks: View {
    let slots: [TimelineSlot]
    let day: Date
    let calendar: Calendar
    let width: CGFloat
    var isCompact = false
    let onOpen: (CalendarEntry) -> Void
    let onCreate: (Date) -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        ZStack(alignment: .topLeading) {
            VStack(spacing: 0) {
                ForEach(0..<24, id: \.self) { hour in
                    let startsAt = calendar.date(bySettingHour: hour, minute: 0, second: 0, of: day) ?? day

                    Color.clear
                        .frame(width: width, height: Timeline.hourHeight)
                        .contentShape(.rect)
                        .onTapGesture { onCreate(startsAt) }
                        .accessibilityElement()
                        .accessibilityLabel(Text(verbatim: language.localized(
                            "New at %@",
                            startsAt.formatted(Date.FormatStyle(date: .abbreviated, time: .shortened, locale: language.locale, timeZone: calendar.timeZone))
                        )))
                        .accessibilityAddTraits(.isButton)
                        .accessibilityHidden(isCompact)
                }
            }

            ForEach(slots) { slot in
                let laneWidth = width / CGFloat(slot.lanes)

                TimelineBlock(entry: slot.entry, calendar: calendar, isCompact: isCompact, showsTime: Timeline.offset(minutes: slot.height) >= 40)
                    .frame(width: max(laneWidth - 2, 4), height: max(Timeline.offset(minutes: slot.height) - 2, 12))
                    .offset(x: laneWidth * CGFloat(slot.lane) + 1, y: Timeline.offset(minutes: slot.top) + 1)
                    .onTapGesture { onOpen(slot.entry) }
            }
        }
        .frame(width: width, height: Timeline.height, alignment: .topLeading)
    }
}

/// A timed item on the grid: its source's color, title and — when there is
/// room — its time and its workspace's dot.
private struct TimelineBlock: View {
    let entry: CalendarEntry
    let calendar: Calendar
    let isCompact: Bool
    let showsTime: Bool

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    var body: some View {
        let color = entry.calendarSource?.color ?? Color.secondary

        VStack(alignment: .leading, spacing: 1) {
            HStack(alignment: .firstTextBaseline, spacing: 4) {
                if !isCompact, let workspace = entry.workspace, model.user?.showsWorkspace(of: entry) == true {
                    WorkspaceDot(hex: workspace.colorHex, size: 7, accessibilityName: language.localized("Workspace: %@", workspace.name))
                }

                Text(verbatim: entry.title)
                    .font(isCompact ? .caption2.weight(.semibold) : .caption.weight(.semibold))
                    .strikethrough(entry.isCompleted)
                    .lineLimit(showsTime ? 2 : 1)
            }

            if showsTime, !isCompact {
                Text(verbatim: entry.startsAt.formatted(Date.FormatStyle(date: .omitted, time: .shortened, locale: language.locale, timeZone: calendar.timeZone)))
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }
        }
        .padding(.horizontal, isCompact ? 2 : 6)
        .padding(.vertical, 2)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .background(color.opacity(entry.isProjected ? 0.1 : 0.2), in: .rect(cornerRadius: 4))
        .overlay(alignment: .leading) {
            Rectangle().fill(color).frame(width: 3).clipShape(.rect(cornerRadius: 1.5))
        }
        .clipped()
        .contentShape(.rect)
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(.isButton)
    }
}

/// An all-day item above the grid.
private struct AllDayChip: View {
    let entry: CalendarEntry
    var isCompact = false
    let onOpen: (CalendarEntry) -> Void

    var body: some View {
        let color = entry.calendarSource?.color ?? Color.secondary

        Text(verbatim: entry.title)
            .font(isCompact ? .system(size: 9, weight: .semibold) : .caption.weight(.semibold))
            .strikethrough(entry.isCompleted)
            .lineLimit(1)
            .foregroundStyle(.white)
            .padding(.horizontal, isCompact ? 2 : 6)
            .padding(.vertical, isCompact ? 1 : 3)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(color, in: .rect(cornerRadius: 4))
            .contentShape(.rect)
            .onTapGesture { onOpen(entry) }
            .accessibilityAddTraits(.isButton)
    }
}
