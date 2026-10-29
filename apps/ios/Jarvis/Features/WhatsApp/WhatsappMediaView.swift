import AVFoundation
import AVKit
import Localization
import Networking
import Observation
import SwiftUI

/// The media of a WhatsApp message. Its `url` is signed and needs no token,
/// so the images and players load it as is; it expires after a few hours,
/// and a failed load asks the conversation for fresh ones (`onFailed`).
///
/// - A photo shows WhatsApp's tiny preview, then itself; a tap opens it full screen.
/// - An audio plays in place, with its waveform as the progress.
/// - A video shows its preview; a tap plays it full screen.
/// - A document opens in the browser.
struct WhatsappMediaView: View {
    let media: WhatsappConversationMessage.Media
    let messageId: String
    let onFailed: () -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        switch media.kind {
        case .image:
            WhatsappImageMedia(media: media, onFailed: onFailed)
        case .video:
            WhatsappVideoMedia(media: media)
        case .audio:
            WhatsappAudioMedia(media: media, onFailed: onFailed)
        case .document:
            WhatsappDocumentMedia(media: media)
        }
    }
}

/// The preview WhatsApp sent, blurred while the real file loads.
private struct WhatsappThumbnail: View {
    let media: WhatsappConversationMessage.Media

    var body: some View {
        if let data = media.thumbnailData, let image = UIImage(data: data) {
            Image(uiImage: image)
                .resizable()
                .scaledToFill()
                .blur(radius: 6)
        } else {
            Color.secondary.opacity(0.15)
        }
    }
}

/// A photo as WhatsApp shows it: as wide as the bubble allows (three
/// quarters of the screen, up to `maxWidth`), in its own proportions as the
/// preview tells them (4:3 without one), no taller than `maxHeight`, cropped
/// to fill.
private struct WhatsappImageMedia: View {
    static let maxWidth: CGFloat = 300
    static let maxHeight: CGFloat = 340

    let media: WhatsappConversationMessage.Media
    let onFailed: () -> Void

    @Environment(\.appLanguage) private var language
    @State private var isFullScreen = false

    /// Width over height, kept between a tall photo cut at `maxHeight` and a wide one.
    private var aspectRatio: CGFloat {
        guard let data = media.thumbnailData, let image = UIImage(data: data), image.size.height > 0 else {
            return 4 / 3
        }

        return min(max(image.size.width / image.size.height, Self.maxWidth / Self.maxHeight), 2)
    }

    var body: some View {
        Button {
            isFullScreen = media.url != nil
        } label: {
            Color.clear
                .aspectRatio(aspectRatio, contentMode: .fit)
                .containerRelativeFrame(.horizontal) { width, _ in min(width * 0.75, Self.maxWidth) }
                .overlay {
                    AsyncImage(url: media.url) { phase in
                        switch phase {
                        case .success(let image):
                            image.resizable().scaledToFill()
                        case .failure:
                            WhatsappThumbnail(media: media)
                                .overlay {
                                    Image(systemName: "arrow.clockwise")
                                        .foregroundStyle(.white)
                                }
                                .onAppear(perform: onFailed)
                        default:
                            WhatsappThumbnail(media: media)
                                .overlay { ProgressView() }
                        }
                    }
                }
                .clipShape(.rect(cornerRadius: 8))
                .contentShape(.rect)
            .overlay {
                if media.url == nil {
                    Label(language.localized("Too big to show here"), systemImage: "iphone")
                        .font(.caption)
                        .padding(6)
                        .background(.ultraThinMaterial, in: .capsule)
                }
            }
        }
        .buttonStyle(.plain)
        .accessibilityLabel(language.text("Photo"))
        .fullScreenCover(isPresented: $isFullScreen) {
            if let url = media.url {
                WhatsappImageViewer(url: url)
            }
        }
    }
}

private struct WhatsappImageViewer: View {
    let url: URL

    @Environment(\.dismiss) private var dismiss
    @Environment(\.appLanguage) private var language
    @State private var zoom: CGFloat = 1

    var body: some View {
        NavigationStack {
            AsyncImage(url: url) { image in
                image.resizable()
                    .scaledToFit()
                    .scaleEffect(zoom)
                    .gesture(MagnifyGesture().onChanged { zoom = max(1, $0.magnification) }.onEnded { _ in
                        withAnimation { zoom = 1 }
                    })
            } placeholder: {
                ProgressView()
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(.black)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Close"), systemImage: "xmark") {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .primaryAction) {
                    ShareLink(item: url)
                }
            }
        }
    }
}

private struct WhatsappVideoMedia: View {
    let media: WhatsappConversationMessage.Media

    @Environment(\.appLanguage) private var language
    @State private var player: AVPlayer?

    var body: some View {
        Button {
            if let url = media.url {
                player = AVPlayer(url: url)
            }
        } label: {
            WhatsappThumbnail(media: media)
                .frame(width: 220, height: 160)
                .clipShape(.rect(cornerRadius: 10))
                .overlay {
                    Image(systemName: media.url == nil ? "iphone" : "play.circle.fill")
                        .font(.system(size: 44))
                        .foregroundStyle(.white)
                        .shadow(radius: 4)
                }
                .overlay(alignment: .bottomLeading) {
                    if let seconds = media.seconds {
                        Text(verbatim: WhatsappAudioPlayer.clock(Double(seconds)))
                            .font(.caption2.monospacedDigit())
                            .foregroundStyle(.white)
                            .padding(6)
                    }
                }
        }
        .buttonStyle(.plain)
        .disabled(media.url == nil)
        .accessibilityLabel(language.text("Video"))
        .fullScreenCover(isPresented: Binding { player != nil } set: { if !$0 { player?.pause(); player = nil } }) {
            if let player {
                VideoPlayer(player: player)
                    .ignoresSafeArea()
                    .onAppear { player.play() }
                    .overlay(alignment: .topLeading) {
                        Button(language.localized("Close"), systemImage: "xmark.circle.fill") {
                            player.pause()
                            self.player = nil
                        }
                        .labelStyle(.iconOnly)
                        .font(.title)
                        .foregroundStyle(.white)
                        .padding()
                    }
            }
        }
    }
}

/// Plays one audio at a time: starting one pauses the one playing.
@MainActor
@Observable
final class WhatsappAudioPlayer {
    private static weak var playing: WhatsappAudioPlayer?

    private(set) var isPlaying = false
    private(set) var isLoading = false
    private(set) var failed = false
    private(set) var current: Double = 0
    private(set) var duration: Double

    @ObservationIgnored private var player: AVPlayer?
    @ObservationIgnored private var timeObserver: Any?
    @ObservationIgnored private var endObserver: (any NSObjectProtocol)?
    @ObservationIgnored private var statusObservation: NSKeyValueObservation?

    init(seconds: Int?) {
        duration = Double(seconds ?? 0)
    }

    var progress: Double {
        duration > 0 ? min(current / duration, 1) : 0
    }

    func toggle(url: URL) {
        if isPlaying {
            pause()

            return
        }

        if player == nil {
            prepare(url: url)
        }

        Self.playing?.pause()
        Self.playing = self

        try? AVAudioSession.sharedInstance().setCategory(.playback)
        try? AVAudioSession.sharedInstance().setActive(true)

        player?.play()
        isPlaying = true
    }

    func pause() {
        player?.pause()
        isPlaying = false
    }

    func seek(to fraction: Double) {
        guard duration > 0 else {
            return
        }

        current = duration * fraction
        player?.seek(to: CMTime(seconds: current, preferredTimescale: 600))
    }

    private func prepare(url: URL) {
        let item = AVPlayerItem(url: url)
        let player = AVPlayer(playerItem: item)

        self.player = player
        isLoading = true
        failed = false

        statusObservation = item.observe(\.status) { [weak self] item, _ in
            let status = item.status
            let seconds = item.duration.seconds

            Task { @MainActor in
                self?.isLoading = false

                if status == .failed {
                    self?.failed = true
                    self?.isPlaying = false
                } else if seconds.isFinite, seconds > 0 {
                    self?.duration = seconds
                }
            }
        }

        timeObserver = player.addPeriodicTimeObserver(forInterval: CMTime(seconds: 0.25, preferredTimescale: 600), queue: .main) { [weak self] time in
            MainActor.assumeIsolated {
                self?.current = time.seconds
            }
        }

        endObserver = NotificationCenter.default.addObserver(forName: AVPlayerItem.didPlayToEndTimeNotification, object: item, queue: .main) { [weak self] _ in
            MainActor.assumeIsolated {
                self?.isPlaying = false
                self?.current = 0
                self?.player?.seek(to: .zero)
            }
        }
    }

    /// `m:ss`.
    static func clock(_ seconds: Double) -> String {
        let whole = seconds.isFinite ? max(Int(seconds), 0) : 0

        return String(format: "%d:%02d", whole / 60, whole % 60)
    }
}

private struct WhatsappAudioMedia: View {
    let media: WhatsappConversationMessage.Media
    let onFailed: () -> Void

    @Environment(\.appLanguage) private var language
    @State private var audio: WhatsappAudioPlayer

    init(media: WhatsappConversationMessage.Media, onFailed: @escaping () -> Void) {
        self.media = media
        self.onFailed = onFailed
        _audio = State(initialValue: WhatsappAudioPlayer(seconds: media.seconds))
    }

    var body: some View {
        HStack(spacing: 10) {
            Button {
                if let url = media.url {
                    audio.toggle(url: url)
                }
            } label: {
                Group {
                    if audio.isLoading && audio.isPlaying {
                        ProgressView()
                    } else {
                        Image(systemName: audio.isPlaying ? "pause.fill" : "play.fill")
                            .font(.title3)
                    }
                }
                .frame(width: 36, height: 36)
            }
            .buttonStyle(.borderless)
            .disabled(media.url == nil)
            .accessibilityLabel(language.text(audio.isPlaying ? "Pause" : "Play"))

            VStack(alignment: .leading, spacing: 2) {
                WhatsappWaveform(bars: media.waveform ?? Array(repeating: 40, count: 40), progress: audio.progress)
                    .frame(width: 160, height: 28)
                    .contentShape(.rect)
                    .gesture(DragGesture(minimumDistance: 0).onEnded { value in
                        audio.seek(to: min(max(value.location.x / 160, 0), 1))
                    })

                Text(verbatim: WhatsappAudioPlayer.clock(audio.isPlaying || audio.current > 0 ? audio.current : audio.duration))
                    .font(.caption2.monospacedDigit())
                    .foregroundStyle(.secondary)
            }
        }
        .onChange(of: audio.failed) { _, failed in
            if failed {
                onFailed()
            }
        }
        .onDisappear {
            audio.pause()
        }
    }
}

/// The audio's bars; the played part in the tint.
private struct WhatsappWaveform: View {
    let bars: [Int]
    let progress: Double

    var body: some View {
        GeometryReader { geometry in
            HStack(alignment: .center, spacing: 2) {
                ForEach(Array(bars.enumerated()), id: \.offset) { index, bar in
                    Capsule()
                        .fill(Double(index) / Double(max(bars.count, 1)) < progress ? Color.accentColor : Color.secondary.opacity(0.45))
                        .frame(height: max(3, geometry.size.height * CGFloat(bar) / 100))
                }
            }
            .frame(maxHeight: .infinity)
        }
        .accessibilityHidden(true)
    }
}

private struct WhatsappDocumentMedia: View {
    let media: WhatsappConversationMessage.Media

    @Environment(\.appLanguage) private var language
    @Environment(\.openURL) private var openURL

    var body: some View {
        Button {
            if let url = media.url {
                openURL(url)
            }
        } label: {
            HStack(spacing: 10) {
                Image(systemName: "doc.fill")
                    .font(.title2)
                    .foregroundStyle(.tint)

                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: media.name ?? language.localized("Document"))
                        .font(.subheadline.weight(.medium))
                        .lineLimit(2)

                    if let size = media.size {
                        Text(Int64(size), format: .byteCount(style: .file))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }

                Spacer(minLength: 0)

                Image(systemName: media.url == nil ? "iphone" : "arrow.down.circle")
                    .foregroundStyle(.secondary)
            }
            .padding(8)
            .frame(minWidth: 200)
            .background(Color.secondary.opacity(0.1), in: .rect(cornerRadius: 10))
        }
        .buttonStyle(.plain)
        .disabled(media.url == nil)
    }
}
