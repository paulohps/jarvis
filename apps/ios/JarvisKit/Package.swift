// swift-tools-version: 6.4
import PackageDescription

/// The logic of the Jarvis iOS app, apart from the UI so it is tested without a
/// simulator UI: the API v1 client, the Sanctum token, the languages, the tabs
/// and deep links, the push registration, the Reverb (Pusher protocol) client,
/// the offline queue of the shopping list and the voice commands.
let package = Package(
    name: "JarvisKit",
    defaultLocalization: "en",
    platforms: [.iOS(.v27), .macOS(.v27)],
    products: [
        .library(name: "Networking", targets: ["Networking"]),
        .library(name: "Auth", targets: ["Auth"]),
        .library(name: "Localization", targets: ["Localization"]),
        .library(name: "Navigation", targets: ["Navigation"]),
        .library(name: "Push", targets: ["Push"]),
        .library(name: "Realtime", targets: ["Realtime"]),
        .library(name: "Offline", targets: ["Offline"]),
        .library(name: "Voice", targets: ["Voice"]),
    ],
    targets: [
        .target(name: "Networking"),
        .target(name: "Auth", dependencies: ["Networking"]),
        .target(name: "Localization", resources: [.process("Resources")]),
        .target(name: "Navigation", dependencies: ["Networking"]),
        .target(name: "Push", dependencies: ["Networking"]),
        .target(name: "Realtime", dependencies: ["Networking"]),
        .target(name: "Offline", dependencies: ["Networking"]),
        .target(name: "Voice", dependencies: ["Networking"]),
        .testTarget(name: "NetworkingTests", dependencies: ["Networking"]),
        .testTarget(name: "AuthTests", dependencies: ["Auth", "Networking"]),
        .testTarget(name: "LocalizationTests", dependencies: ["Localization"]),
        .testTarget(name: "NavigationTests", dependencies: ["Navigation", "Networking"]),
        .testTarget(name: "PushTests", dependencies: ["Push", "Networking"]),
        .testTarget(name: "RealtimeTests", dependencies: ["Realtime", "Networking"]),
        .testTarget(name: "OfflineTests", dependencies: ["Offline", "Networking"]),
        .testTarget(name: "VoiceTests", dependencies: ["Voice", "Networking"]),
    ]
)
