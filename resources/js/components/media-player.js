/**
 * The audio and video players of the WhatsApp screen (`x-data="mediaPlayer"`,
 * rendered by <x-whatsapp.audio-player> and <x-whatsapp.video-player>), in
 * place of the browser's own controls: play and pause, a seek bar, the time,
 * the playback speed and, for a video, sound and full screen.
 *
 * The <audio> or <video> is `x-ref="media"`. One player plays at a time:
 * starting one pauses the others, as WhatsApp does.
 *
 * Options: `seconds`, the length WhatsApp told, shown before the file is
 * there; `load`, the message whose file is not in Jarvis yet — pressing
 * play asks the Livewire component to load it (`loadMedia`), then plays.
 */
const PLAY_EVENT = 'jarvis-media-play';

const RATES = [1, 1.5, 2];

/**
 * A duration WhatsApp's voice notes may not tell until played through
 * (Infinity, NaN) counts as unknown.
 */
function finite(seconds) {
    return Number.isFinite(seconds) && seconds > 0 ? seconds : 0;
}

function clock(seconds) {
    const whole = Math.floor(finite(seconds));

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mediaPlayer', (options = {}) => ({
        load: options.load ?? null,
        playing: false,
        waiting: false,
        failed: false,
        muted: false,
        current: 0,
        duration: finite(options.seconds ?? 0),
        rate: 1,

        init() {
            const media = this.$refs.media;
            const sync = () => {
                this.current = media.currentTime;
                this.duration = finite(media.duration) || this.duration;
            };

            media.addEventListener('loadedmetadata', sync);
            media.addEventListener('durationchange', sync);
            media.addEventListener('timeupdate', sync);
            media.addEventListener('play', () => (this.playing = true));
            media.addEventListener('pause', () => (this.playing = false));
            media.addEventListener('waiting', () => (this.waiting = true));
            media.addEventListener('playing', () => (this.waiting = false));
            media.addEventListener('error', () => {
                this.failed = true;
                this.waiting = false;
            });
            media.addEventListener('ended', () => {
                this.playing = false;
                media.currentTime = 0;
            });

            this.pauseOthers = (event) => {
                if (event.detail !== media) {
                    media.pause();
                }
            };
            window.addEventListener(PLAY_EVENT, this.pauseOthers);
        },

        destroy() {
            window.removeEventListener(PLAY_EVENT, this.pauseOthers);
        },

        get progress() {
            return this.duration > 0 ? Math.min(100, (this.current / this.duration) * 100) : 0;
        },

        get time() {
            return this.playing || this.current > 0
                ? clock(this.current)
                : clock(this.duration);
        },

        get rateLabel() {
            return `${this.rate}×`;
        },

        /**
         * Whether a bar at `index` of `count` is in the part already played.
         */
        played(index, count) {
            return (index + 0.5) / count <= this.progress / 100;
        },

        async toggle() {
            const media = this.$refs.media;

            if (!media.paused) {
                media.pause();

                return;
            }

            this.failed = false;

            if (!media.getAttribute('src') && this.load) {
                this.waiting = true;

                try {
                    await this.$wire.loadMedia(this.load);
                } finally {
                    this.waiting = false;
                }

                await this.$nextTick();

                if (!this.$refs.media.getAttribute('src')) {
                    this.failed = true;

                    return;
                }

                this.load = null;
            }

            window.dispatchEvent(new CustomEvent(PLAY_EVENT, { detail: this.$refs.media }));
            this.$refs.media.play().catch(() => (this.failed = true));
        },

        seek(event) {
            const media = this.$refs.media;
            const seconds = Number(event.target.value);

            if (Number.isFinite(seconds)) {
                media.currentTime = seconds;
                this.current = seconds;
            }
        },

        cycleRate() {
            this.rate = RATES[(RATES.indexOf(this.rate) + 1) % RATES.length];
            this.$refs.media.playbackRate = this.rate;
        },

        toggleMute() {
            this.muted = !this.muted;
            this.$refs.media.muted = this.muted;
        },

        fullscreen() {
            const frame = this.$refs.frame;
            const media = this.$refs.media;

            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (frame?.requestFullscreen) {
                frame.requestFullscreen();
            } else if (media.webkitEnterFullscreen) {
                // iOS Safari only lets the <video> itself go full screen.
                media.webkitEnterFullscreen();
            }
        },
    }));
});
