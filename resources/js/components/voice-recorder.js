/**
 * Records a voice note in the browser (MediaRecorder) and uploads it to a
 * Livewire file property: `x-data="voiceRecorder('scheduleFile')"` for the
 * scheduled WhatsApp messages, or `voiceRecorder('attachment', 'send')` in
 * the chat composer, which calls that Livewire method once uploaded.
 * WhatsApp gets it as a voice note (the Evolution API converts it). Up to
 * MAX_SECONDS; `cancel()` drops it, and nothing is kept when the
 * microphone is refused.
 */
const MAX_SECONDS = 15 * 60;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('voiceRecorder', (property, afterUpload = null) => ({
        recording: false,
        cancelled: false,
        seconds: 0,
        failed: false,
        recorder: null,
        timer: null,

        get elapsed() {
            const minutes = Math.floor(this.seconds / 60);
            const seconds = String(this.seconds % 60).padStart(2, '0');

            return `${minutes}:${seconds}`;
        },

        async start() {
            this.failed = false;
            this.cancelled = false;

            let stream;

            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch {
                this.failed = true;

                return;
            }

            const type = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/mp4'].find((candidate) => MediaRecorder.isTypeSupported(candidate)) ?? '';
            const chunks = [];

            this.recorder = new MediaRecorder(stream, type ? { mimeType: type } : {});
            this.recorder.addEventListener('dataavailable', (event) => chunks.push(event.data));
            this.recorder.addEventListener('stop', () => {
                stream.getTracks().forEach((track) => track.stop());

                if (this.cancelled) {
                    return;
                }

                const mime = (this.recorder.mimeType || 'audio/webm').split(';')[0];
                const extension = { 'audio/ogg': 'ogg', 'audio/webm': 'webm', 'audio/mp4': 'm4a' }[mime] ?? 'webm';
                const file = new File(chunks, `voice-note.${extension}`, { type: mime });

                this.$wire.upload(property, file, () => afterUpload && this.$wire.call(afterUpload));
            });

            this.recorder.start();
            this.recording = true;
            this.seconds = 0;
            this.timer = setInterval(() => {
                this.seconds++;

                if (this.seconds >= MAX_SECONDS) {
                    this.stop();
                }
            }, 1000);
        },

        stop() {
            clearInterval(this.timer);
            this.recording = false;
            this.recorder?.state === 'recording' && this.recorder.stop();
        },

        cancel() {
            this.cancelled = true;
            this.stop();
        },

        destroy() {
            this.cancelled = true;
            clearInterval(this.timer);
            this.recorder?.state === 'recording' && this.recorder.stop();
        },
    }));
});
