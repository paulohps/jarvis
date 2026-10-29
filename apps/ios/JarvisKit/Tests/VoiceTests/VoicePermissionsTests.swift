import Foundation
import XCTest
@testable import Voice

final class VoicePermissionsTests: XCTestCase {
    func testAskingBothPermissionsForTheFirstTimeGrantsAccess() async {
        let authorizer = FakeVoiceAuthorizer(microphone: .notDetermined, speech: .notDetermined, answers: true)

        let access = await VoicePermissions(authorizer: authorizer).requestAccess()

        XCTAssertEqual(access, .granted)
        XCTAssertEqual(authorizer.prompts, ["microphone", "speech"])
    }

    func testARefusedMicrophoneIsExplainedAndTheSpeechIsNotAsked() async {
        let authorizer = FakeVoiceAuthorizer(microphone: .notDetermined, speech: .notDetermined, answers: false)

        let access = await VoicePermissions(authorizer: authorizer).requestAccess()

        XCTAssertEqual(access, .microphoneDenied)
        XCTAssertEqual(authorizer.prompts, ["microphone"])
        XCTAssertNotNil(access.explanationKey)
    }

    func testAPermissionRefusedBeforeIsNotAskedAgain() async {
        let authorizer = FakeVoiceAuthorizer(microphone: .authorized, speech: .denied, answers: true)

        let access = await VoicePermissions(authorizer: authorizer).requestAccess()

        XCTAssertEqual(access, .speechRecognitionDenied)
        XCTAssertEqual(authorizer.prompts, [])
        XCTAssertTrue(access.explanationKey?.contains("iOS Settings") == true)
    }

    func testTheCurrentAccessNeverPrompts() async {
        let authorizer = FakeVoiceAuthorizer(microphone: .notDetermined, speech: .notDetermined, answers: true)
        let permissions = VoicePermissions(authorizer: authorizer)

        let unknown = await permissions.currentAccess()
        XCTAssertNil(unknown)

        let denied = await VoicePermissions(authorizer: FakeVoiceAuthorizer(microphone: .denied, speech: .authorized, answers: true)).currentAccess()
        XCTAssertEqual(denied, .microphoneDenied)
        XCTAssertEqual(authorizer.prompts, [])
    }
}

final class FakeVoiceAuthorizer: VoiceAuthorizing, @unchecked Sendable {
    private let lock = NSLock()
    private let microphone: VoiceAuthorization
    private let speech: VoiceAuthorization
    private let answers: Bool
    private var asked: [String] = []

    init(microphone: VoiceAuthorization, speech: VoiceAuthorization, answers: Bool) {
        self.microphone = microphone
        self.speech = speech
        self.answers = answers
    }

    var prompts: [String] {
        lock.withLock { asked }
    }

    func microphoneAuthorization() async -> VoiceAuthorization {
        microphone
    }

    func requestMicrophone() async -> Bool {
        lock.withLock { asked.append("microphone") }

        return answers
    }

    func speechAuthorization() async -> VoiceAuthorization {
        speech
    }

    func requestSpeech() async -> Bool {
        lock.withLock { asked.append("speech") }

        return answers
    }
}
