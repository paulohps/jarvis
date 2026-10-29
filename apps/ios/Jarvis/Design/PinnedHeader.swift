import SwiftUI

extension View {
    /// What sits above a screen's scrolling content — its filters, the
    /// agenda's period and days: it stays put while the content scrolls (the
    /// content fades under it, as under the navigation bar), and moves down
    /// with it on a pull to refresh, as the large title does. Stacked headers
    /// move together.
    ///
    /// Everything above the scroll view must be in a pinned header: a view
    /// laid out above it instead stops the large title from following the
    /// pull, and the headers then slide over it.
    func pinnedHeader<Header: View>(@ViewBuilder _ header: () -> Header) -> some View {
        modifier(PinnedHeader(header: header()))
    }
}

private struct PinnedHeader<Header: View>: ViewModifier {
    /// Where the content is, against the top inset (navigation bar, search
    /// field and the headers above it).
    private struct ScrollEdge: Equatable {
        let offset: CGFloat
        let inset: CGFloat
    }

    let header: Header

    /// How far the content is pulled past its top.
    @State private var pullDistance: CGFloat = 0

    /// The top inset with the navigation bar expanded (large title, search
    /// field). It is the line a pull starts from: while the bar collapses or
    /// expands the inset changes before the content follows, and measuring
    /// from the current inset there reads a pull that is not one, and the
    /// headers jump.
    @State private var expandedInset: CGFloat = 0

    func body(content: Content) -> some View {
        content
            .onScrollGeometryChange(for: ScrollEdge.self) { geometry in
                ScrollEdge(offset: geometry.contentOffset.y, inset: geometry.contentInsets.top)
            } action: { _, edge in
                expandedInset = max(expandedInset, edge.inset)
                pullDistance = max(0, -edge.offset - expandedInset)
            }
            .onScrollPhaseChange { _, phase, context in
                // At rest at the top the bar is expanded: start again from
                // there, as the headers may have changed height (a filter row
                // shown or hidden).
                let geometry = context.geometry

                if phase == .idle, abs(geometry.contentOffset.y + geometry.contentInsets.top) < 1 {
                    expandedInset = geometry.contentInsets.top
                    pullDistance = 0
                }
            }
            .safeAreaBar(edge: .top) {
                header.offset(y: pullDistance)
            }
    }
}
