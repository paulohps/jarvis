<?php

namespace App\Services\Slack;

/**
 * Slack names its emojis (`:thumbsup:`) where the chat screen uses the
 * characters: the reactions sent to Slack need the name, the ones read
 * from it and the `:name:` in texts need the character. Only the names
 * here are offered as reactions (ChatCapabilities::reactionEmojis); a
 * name not here shows as `:name:`.
 */
final class SlackEmoji
{
    /**
     * Slack name => character. The first name of a character is the one
     * sent; the others are read.
     *
     * @var array<string, string>
     */
    private const array NAMES = [
        '+1' => '👍', 'thumbsup' => '👍', '-1' => '👎', 'thumbsdown' => '👎',
        'heart' => '❤️', 'joy' => '😂', 'open_mouth' => '😮', 'cry' => '😢', 'pray' => '🙏',
        'smile' => '😄', 'grinning' => '😀', 'laughing' => '😆', 'sweat_smile' => '😅', 'rolling_on_the_floor_laughing' => '🤣',
        'slightly_smiling_face' => '🙂', 'wink' => '😉', 'blush' => '😊', 'heart_eyes' => '😍', 'kissing_heart' => '😘',
        'thinking_face' => '🤔', 'neutral_face' => '😐', 'expressionless' => '😑', 'unamused' => '😒', 'face_with_rolling_eyes' => '🙄',
        'grimacing' => '😬', 'relieved' => '😌', 'pensive' => '😔', 'sleeping' => '😴', 'mask' => '😷',
        'sunglasses' => '😎', 'nerd_face' => '🤓', 'confused' => '😕', 'worried' => '😟', 'disappointed' => '😞',
        'sob' => '😭', 'scream' => '😱', 'angry' => '😠', 'rage' => '😡', 'exploding_head' => '🤯',
        'hugging_face' => '🤗', 'star-struck' => '🤩', 'partying_face' => '🥳', 'melting_face' => '🫠', 'saluting_face' => '🫡',
        'skull' => '💀', 'ghost' => '👻', 'robot_face' => '🤖', 'poop' => '💩', 'clown_face' => '🤡',
        'wave' => '👋', 'raised_hands' => '🙌', 'clap' => '👏', 'ok_hand' => '👌', 'v' => '✌️',
        'crossed_fingers' => '🤞', 'muscle' => '💪', 'point_up' => '☝️', 'point_right' => '👉', 'point_left' => '👈',
        'handshake' => '🤝', 'raised_hand' => '✋', 'facepunch' => '👊', 'eyes' => '👀', 'brain' => '🧠',
        'fire' => '🔥', 'tada' => '🎉', 'sparkles' => '✨', 'star' => '⭐', 'star2' => '🌟',
        '100' => '💯', 'white_check_mark' => '✅', 'heavy_check_mark' => '✔️', 'x' => '❌', 'heavy_plus_sign' => '➕',
        'question' => '❓', 'exclamation' => '❗', 'warning' => '⚠️', 'no_entry' => '⛔', 'no_entry_sign' => '🚫',
        'rocket' => '🚀', 'boom' => '💥', 'zap' => '⚡', 'bulb' => '💡', 'bell' => '🔔',
        'eyes_right' => '👀', 'hourglass' => '⌛', 'clock1' => '🕐', 'calendar' => '📅', 'memo' => '📝',
        'coffee' => '☕', 'beer' => '🍺', 'beers' => '🍻', 'pizza' => '🍕', 'cake' => '🍰',
        'gift' => '🎁', 'trophy' => '🏆', 'medal' => '🏅', 'dart' => '🎯', 'computer' => '💻',
        'bug' => '🐛', 'lock' => '🔒', 'key' => '🔑', 'mag' => '🔍', 'wrench' => '🔧',
        'hammer' => '🔨', 'package' => '📦', 'chart_with_upwards_trend' => '📈', 'chart_with_downwards_trend' => '📉', 'moneybag' => '💰',
        'dollar' => '💵', 'money_with_wings' => '💸', 'house' => '🏠', 'car' => '🚗', 'airplane' => '✈️',
        'sunny' => '☀️', 'rain_cloud' => '🌧️', 'umbrella' => '☂️', 'snowflake' => '❄️', 'rainbow' => '🌈',
        'dog' => '🐶', 'cat' => '🐱', 'unicorn_face' => '🦄', 'bee' => '🐝', 'turtle' => '🐢',
        'blue_heart' => '💙', 'green_heart' => '💚', 'yellow_heart' => '💛', 'purple_heart' => '💜', 'black_heart' => '🖤',
        'broken_heart' => '💔', 'heartpulse' => '💗', 'two_hearts' => '💕', 'kiss' => '💋', 'speech_balloon' => '💬',
        'thought_balloon' => '💭', 'zzz' => '💤', 'eyeglasses' => '👓', 'tv' => '📺', 'phone' => '☎️',
        'email' => '📧', 'inbox_tray' => '📥', 'outbox_tray' => '📤', 'pushpin' => '📌', 'paperclip' => '📎',
        'arrow_up' => '⬆️', 'arrow_down' => '⬇️', 'arrow_right' => '➡️', 'arrow_left' => '⬅️', 'repeat' => '🔁',
        'ok' => '🆗', 'new' => '🆕', 'sos' => '🆘', 'cool' => '🆒', 'top' => '🔝',
        'red_circle' => '🔴', 'large_green_circle' => '🟢', 'large_yellow_circle' => '🟡', 'large_blue_circle' => '🔵', 'white_circle' => '⚪',
        'thumbsup_all' => '👍', 'man-shrugging' => '🤷‍♂️', 'woman-shrugging' => '🤷‍♀️', 'shrug' => '🤷', 'man-facepalming' => '🤦‍♂️',
        'woman-facepalming' => '🤦‍♀️', 'face_palm' => '🤦', 'eyes_up' => '👀', 'smiling_face_with_3_hearts' => '🥰', 'yum' => '😋',
        'stuck_out_tongue' => '😛', 'stuck_out_tongue_winking_eye' => '😜', 'zany_face' => '🤪', 'money_mouth_face' => '🤑', 'hushed' => '😯',
        'flushed' => '😳', 'pleading_face' => '🥺', 'smirk' => '😏', 'innocent' => '😇', 'upside_down_face' => '🙃',
        'face_with_hand_over_mouth' => '🤭', 'shushing_face' => '🤫', 'lying_face' => '🤥', 'face_vomiting' => '🤮', 'nauseated_face' => '🤢',
        'sneezing_face' => '🤧', 'hot_face' => '🥵', 'cold_face' => '🥶', 'woozy_face' => '🥴', 'dizzy_face' => '😵',
        'yawning_face' => '🥱', 'triumph' => '😤', 'cursing_face' => '🤬', 'fearful' => '😨', 'cold_sweat' => '😰',
        'disappointed_relieved' => '😥', 'tired_face' => '😫', 'weary' => '😩', 'persevere' => '😣', 'confounded' => '😖',
        'raised_hand_with_fingers_splayed' => '🖐️', 'spock-hand' => '🖖', 'call_me_hand' => '🤙', 'metal' => '🤘', 'love_you_gesture' => '🤟',
        'writing_hand' => '✍️', 'pinched_fingers' => '🤌', 'pinching_hand' => '🤏', 'ok_woman' => '🙆‍♀️', 'ok_man' => '🙆‍♂️',
        'raising_hand' => '🙋', 'bow' => '🙇', 'person_doing_cartwheel' => '🤸', 'dancer' => '💃', 'man_dancing' => '🕺',
        'checkered_flag' => '🏁', 'soccer' => '⚽', 'basketball' => '🏀', 'football' => '🏈', 'tennis' => '🎾',
        'video_game' => '🎮', 'musical_note' => '🎵', 'notes' => '🎶', 'microphone' => '🎤', 'headphones' => '🎧',
        'art' => '🎨', 'movie_camera' => '🎥', 'camera' => '📷', 'books' => '📚', 'book' => '📖',
        'pencil2' => '✏️', 'scissors' => '✂️', 'link' => '🔗', 'gear' => '⚙️', 'hourglass_flowing_sand' => '⏳',
        'alarm_clock' => '⏰', 'stopwatch' => '⏱️', 'date' => '📅', 'clipboard' => '📋', 'bar_chart' => '📊',
        'bookmark' => '🔖', 'label' => '🏷️', 'mega' => '📣', 'loudspeaker' => '📢', 'bellhop_bell' => '🛎️',
        'sparkling_heart' => '💖', 'heart_decoration' => '💟', 'seedling' => '🌱', 'four_leaf_clover' => '🍀', 'rose' => '🌹',
        'sunflower' => '🌻', 'cactus' => '🌵', 'palm_tree' => '🌴', 'earth_americas' => '🌎', 'full_moon' => '🌕',
        'apple' => '🍎', 'banana' => '🍌', 'taco' => '🌮', 'hamburger' => '🍔', 'fries' => '🍟',
        'doughnut' => '🍩', 'cookie' => '🍪', 'wine_glass' => '🍷', 'cocktail' => '🍸', 'champagne' => '🍾',
        'tea' => '🍵', 'birthday' => '🎂', 'balloon' => '🎈', 'confetti_ball' => '🎊', 'crown' => '👑',
        'gem' => '💎', 'ring' => '💍', 'shopping_trolley' => '🛒', 'bus' => '🚌', 'train' => '🚋',
        'bike' => '🚲', 'ship' => '🚢', 'anchor' => '⚓', 'hospital' => '🏥', 'office' => '🏢',
        'school' => '🏫', 'church' => '⛪', 'beach_with_umbrella' => '🏖️', 'mountain' => '⛰️', 'volcano' => '🌋',
        'construction' => '🚧', 'traffic_light' => '🚥', 'vertical_traffic_light' => '🚦', 'recycle' => '♻️', 'white_flag' => '🏳️',
        'triangular_flag_on_post' => '🚩', 'flag-br' => '🇧🇷', 'flag-us' => '🇺🇸', 'brazil' => '🇧🇷', 'us' => '🇺🇸',
        'heavy_multiplication_x' => '✖️', 'heavy_minus_sign' => '➖', 'heavy_division_sign' => '➗', 'infinity' => '♾️', 'bangbang' => '‼️',
        'interrobang' => '⁉️', 'grey_question' => '❔', 'grey_exclamation' => '❕', 'copyright' => '©️', 'tm' => '™️',
        'one' => '1️⃣', 'two' => '2️⃣', 'three' => '3️⃣', 'four' => '4️⃣', 'five' => '5️⃣',
        'six' => '6️⃣', 'seven' => '7️⃣', 'eight' => '8️⃣', 'nine' => '9️⃣', 'keycap_ten' => '🔟',
        'zero' => '0️⃣', 'hash' => '#️⃣', 'eye' => '👁️', 'tongue' => '👅', 'ear' => '👂',
        'nose' => '👃', 'foot' => '🦶', 'leg' => '🦵', 'baby' => '👶', 'santa' => '🎅',
        'angel' => '👼', 'alien' => '👽', 'space_invader' => '👾', 'jack_o_lantern' => '🎃', 'christmas_tree' => '🎄',
        'fireworks' => '🎆', 'sparkler' => '🎇', 'moon' => '🌙', 'cloud' => '☁️', 'ocean' => '🌊',
    ];

    /**
     * Character => the Slack name sent for it.
     *
     * @var array<string, string>|null
     */
    private static ?array $byCharacter = null;

    /**
     * The characters offered as reactions on Slack.
     *
     * @return list<string>
     */
    public static function characters(): array
    {
        return array_keys(self::byCharacter());
    }

    /**
     * The character of a Slack name (`thumbsup`, `thumbsup::skin-tone-2`),
     * or null for one not known.
     */
    public static function characterOf(string $name): ?string
    {
        $base = explode('::', $name, 2)[0];

        return self::NAMES[$base] ?? null;
    }

    /**
     * The Slack name of a character, or null for one Slack has no name
     * for here.
     */
    public static function nameOf(string $character): ?string
    {
        return self::byCharacter()[$character] ?? self::byCharacter()[rtrim($character, "\u{FE0F}")] ?? null;
    }

    /**
     * Turns the `:name:` of a text into their characters.
     */
    public static function replaceShortcodes(string $text): string
    {
        return (string) preg_replace_callback('/:([a-z0-9_+\-]+)(?:::skin-tone-\d)?:/i', function (array $match): string {
            return self::characterOf($match[1]) ?? $match[0];
        }, $text);
    }

    /**
     * @return array<string, string>
     */
    private static function byCharacter(): array
    {
        if (self::$byCharacter === null) {
            self::$byCharacter = [];

            foreach (self::NAMES as $name => $character) {
                self::$byCharacter[$character] ??= $name;
            }
        }

        return self::$byCharacter;
    }
}
