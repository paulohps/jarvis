import AVFoundation
import Localization
import Networking
import Observation
import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

/// The file a scheduled WhatsApp message sends: a photo or video from the
/// library, a document from Files, or a voice note recorded here. The
/// server takes up to `maxBytes`; an audio goes as a voice note, without
/// text.
struct WhatsappScheduleAttachment: Equatable {
    enum Kind {
        case image, video, audio, document
    }

    /// The server's limit for a file (12 MB).
    static let maxBytes = 12 * 1024 * 1024

    let fileName: String
    let mimeType: String
    let data: Data

    var kind: Kind {
        switch true {
        case mimeType.hasPrefix("image/"): .image
        case mimeType.hasPrefix("video/"): .video
        case mimeType.hasPrefix("audio/"): .audio
        default: .document
        }
    }

    var isTooBig: Bool {
        data.count > Self.maxBytes
    }

    var systemImage: String {
        switch kind {
        case .image: "photo"
        case .video: "video"
        case .audio: "mic"
        case .document: "doc"
        }
    }

    var multipartFile: MultipartForm.File {
        MultipartForm.File(name: "file", fileName: fileName, mimeType: mimeType, data: data)
    }

    /// A file of the type, named after it when it has no name.
    init(data: Data, type: UTType?, fileName: String? = nil) {
        let type = type ?? .data
        let fileExtension = type.preferredFilenameExtension ?? "bin"

        self.data = data
        self.mimeType = type.preferredMIMEType ?? "application/octet-stream"
        self.fileName = fileName ?? "file.\(fileExtension)"
    }

    /// A photo or video picked in the library.
    static func load(_ item: PhotosPickerItem) async -> WhatsappScheduleAttachment? {
        guard let data = try? await item.loadTransferable(type: Data.self) else {
            return nil
        }

        let type = item.supportedContentTypes.first { $0.conforms(to: .image) || $0.conforms(to: .movie) } ?? item.supportedContentTypes.first

        return WhatsappScheduleAttachment(data: data, type: type)
    }

    /// A document picked in Files.
    static func load(_ url: URL) -> WhatsappScheduleAttachment? {
        let isScoped = url.startAccessingSecurityScopedResource()
        defer {
            if isScoped {
                url.stopAccessingSecurityScopedResource()
            }
        }

        guard let data = try? Data(contentsOf: url) else {
            return nil
        }

        return WhatsappScheduleAttachment(data: data, type: UTType(filenameExtension: url.pathExtension), fileName: url.lastPathComponent)
    }
}

/// Records a voice note (AAC in an `.m4a`, sent as `audio/mp4`) and plays
/// it back before it is scheduled.
@MainActor
@Observable
final class WhatsappVoiceRecorder {
    /// The longest voice note, as the web.
    static let maxSeconds: TimeInterval = 15 * 60

    private(set) var isRecording = false
    private(set) var isPlaying = false
    private(set) var elapsed: TimeInterval = 0
    private(set) var isDenied = false

    @ObservationIgnored private var recorder: AVAudioRecorder?
    @ObservationIgnored private var player: AVAudioPlayer?
    @ObservationIgnored private var timer: Timer?
    @ObservationIgnored private let fileURL = FileManager.default.temporaryDirectory.appending(path: "whatsapp-voice-note.m4a")

    var clock: String {
        WhatsappAudioPlayer.clock(elapsed)
    }

    func start() async {
        isDenied = false

        guard await AVAudioApplication.requestRecordPermission() else {
            isDenied = true

            return
        }

        stopPlaying()

        do {
            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.playAndRecord, mode: .default, options: [.defaultToSpeaker])
            try session.setActive(true)

            let recorder = try AVAudioRecorder(url: fileURL, settings: [
                AVFormatIDKey: kAudioFormatMPEG4AAC,
                AVSampleRateKey: 44_100,
                AVNumberOfChannelsKey: 1,
                AVEncoderAudioQualityKey: AVAudioQuality.medium.rawValue,
            ])

            guard recorder.record(forDuration: Self.maxSeconds) else {
                return
            }

            self.recorder = recorder
            isRecording = true
            elapsed = 0
            timer = Timer.scheduledTimer(withTimeInterval: 0.25, repeats: true) { [weak self] _ in
                Task { @MainActor in
                    guard let self, let recorder = self.recorder else {
                        return
                    }

                    if recorder.isRecording {
                        self.elapsed = recorder.currentTime
                    } else if self.isRecording {
                        _ = self.stop()
                    }
                }
            }
        } catch {
            isRecording = false
        }
    }

    /// The voice note recorded, or nil when nothing was.
    func stop() -> WhatsappScheduleAttachment? {
        timer?.invalidate()
        timer = nil
        recorder?.stop()
        recorder = nil
        isRecording = false
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)

        guard let data = try? Data(contentsOf: fileURL), !data.isEmpty else {
            return nil
        }

        return WhatsappScheduleAttachment(data: data, type: .mpeg4Audio, fileName: "voice-note.m4a")
    }

    func togglePlayback(_ attachment: WhatsappScheduleAttachment) {
        if isPlaying {
            stopPlaying()

            return
        }

        try? AVAudioSession.sharedInstance().setCategory(.playback)
        try? AVAudioSession.sharedInstance().setActive(true)
        player = try? AVAudioPlayer(data: attachment.data)
        isPlaying = player?.play() ?? false

        if let duration = player?.duration {
            Task { [weak self] in
                try? await Task.sleep(for: .seconds(duration))
                self?.isPlaying = self?.player?.isPlaying ?? false
            }
        }
    }

    func stopPlaying() {
        player?.stop()
        player = nil
        isPlaying = false
    }

    /// Stops whatever is going on when the form closes.
    func discard() {
        _ = stop()
        stopPlaying()
        try? FileManager.default.removeItem(at: fileURL)
    }
}

/// The file section of the schedule form: the file chosen, with its size and
/// a playback of a voice note, or the ways to choose one.
struct WhatsappScheduleAttachmentSection: View {
    @Binding var attachment: WhatsappScheduleAttachment?
    let recorder: WhatsappVoiceRecorder
    let failure: String?

    @Environment(\.appLanguage) private var language
    @State private var photoItem: PhotosPickerItem?
    @State private var isImportingDocument = false
    @State private var loadFailed = false

    var body: some View {
        Section {
            if let attachment {
                HStack(spacing: 10) {
                    Image(systemName: attachment.systemImage)
                        .foregroundStyle(.secondary)

                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: attachment.kind == .audio ? language.localized("Voice note") : attachment.fileName)
                            .lineLimit(1)
                        Text(verbatim: ByteCountFormatter.string(fromByteCount: Int64(attachment.data.count), countStyle: .file))
                            .font(.caption)
                            .foregroundStyle(attachment.isTooBig ? .red : .secondary)
                    }

                    Spacer()

                    if attachment.kind == .audio {
                        Button(language.localized(recorder.isPlaying ? "Pause" : "Play"), systemImage: recorder.isPlaying ? "pause.circle.fill" : "play.circle.fill") {
                            recorder.togglePlayback(attachment)
                        }
                        .labelStyle(.iconOnly)
                        .font(.title2)
                        .buttonStyle(.borderless)
                    }

                    Button(language.localized("Remove the file"), systemImage: "xmark.circle.fill") {
                        recorder.stopPlaying()
                        self.attachment = nil
                    }
                    .labelStyle(.iconOnly)
                    .foregroundStyle(.secondary)
                    .buttonStyle(.borderless)
                    .accessibilityIdentifier("whatsapp.schedule.remove-file")
                }
            } else if recorder.isRecording {
                HStack {
                    Image(systemName: "record.circle")
                        .foregroundStyle(.red)
                        .symbolEffect(.pulse)
                    Text(verbatim: recorder.clock)
                        .monospacedDigit()

                    Spacer()

                    Button(language.localized("Stop"), systemImage: "stop.circle.fill") {
                        attachment = recorder.stop()
                    }
                    .buttonStyle(.borderless)
                    .foregroundStyle(.red)
                    .accessibilityIdentifier("whatsapp.schedule.stop-recording")
                }
            } else {
                PhotosPicker(selection: $photoItem, matching: .any(of: [.images, .videos])) {
                    Label(language.localized("Photo or video"), systemImage: "photo.on.rectangle")
                }
                .accessibilityIdentifier("whatsapp.schedule.attach-photo")

                Button(language.localized("Document"), systemImage: "doc") {
                    isImportingDocument = true
                }
                .accessibilityIdentifier("whatsapp.schedule.attach-document")

                Button(language.localized("Record a voice note"), systemImage: "mic") {
                    Task { await recorder.start() }
                }
                .accessibilityIdentifier("whatsapp.schedule.record")
            }
        } header: {
            Text(verbatim: language.localized("File"))
        } footer: {
            if let message = footer {
                Text(verbatim: message)
                    .foregroundStyle(.red)
            }
        }
        .onChange(of: photoItem) { _, item in
            guard let item else {
                return
            }

            Task {
                let loaded = await WhatsappScheduleAttachment.load(item)
                loadFailed = loaded == nil
                attachment = loaded ?? attachment
                photoItem = nil
            }
        }
        .fileImporter(isPresented: $isImportingDocument, allowedContentTypes: [.item]) { result in
            if case .success(let url) = result {
                let loaded = WhatsappScheduleAttachment.load(url)
                loadFailed = loaded == nil
                attachment = loaded ?? attachment
            }
        }
    }

    private var footer: String? {
        if recorder.isDenied {
            return language.localized("Allow Jarvis to use the microphone in Settings to record a voice note.")
        }

        if loadFailed {
            return language.localized("This file could not be read.")
        }

        if attachment?.isTooBig == true {
            return language.localized("The file is over 12 MB, the most WhatsApp messages take here.")
        }

        return failure
    }
}
