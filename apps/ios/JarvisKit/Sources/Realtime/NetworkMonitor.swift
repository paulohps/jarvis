import Foundation
import Network

/// Tells when the network comes back, so the realtime connection is opened
/// again right away instead of after the backoff.
public final class NetworkMonitor: @unchecked Sendable {
    private let monitor = NWPathMonitor()
    private let queue = DispatchQueue(label: "app.jarvis.network-monitor")
    private let lock = NSLock()
    private var wasSatisfied = true

    public init() {}

    /// - Parameter onAvailable: called on each offline → online transition.
    public func start(onAvailable: @escaping @Sendable () -> Void) {
        monitor.pathUpdateHandler = { [weak self] path in
            guard let self else {
                return
            }

            let isSatisfied = path.status == .satisfied
            let cameBack = self.lock.withLock {
                defer { self.wasSatisfied = isSatisfied }

                return isSatisfied && !self.wasSatisfied
            }

            if cameBack {
                onAvailable()
            }
        }

        monitor.start(queue: queue)
    }

    public func stop() {
        monitor.cancel()
    }
}
