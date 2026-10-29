import SwiftUI

/// The opening of the app while the session loads: the launch screen
/// (`UILaunchScreen` in Info.plist) drawn again, pixel for pixel, so the
/// system's launch image hands over to it without a jump. A spinner shows
/// up only when `/me` takes longer than a moment (no cached user yet).
struct SplashView: View {
    @State private var isSlow = false

    var body: some View {
        ZStack {
            Color(.launchBackground)
                .ignoresSafeArea()

            Image(.launchMark)
                .accessibilityLabel("Jarvis")

            if isSlow {
                ProgressView()
                    .tint(.white)
                    .offset(y: 110)
                    .transition(.opacity)
            }
        }
        .task {
            try? await Task.sleep(for: .seconds(1))
            withAnimation { isSlow = true }
        }
    }
}

#Preview {
    SplashView()
}
