<?php

namespace App\Domain\Import\ClickUp\Chat;

use App\Domain\Chat\EmojiCatalog;

/**
 * Reacciones del chat de ClickUp → emoji del selector del chat (D-117, D-276). ClickUp las da por
 * su nombre corto («+1», «heart», «thumbs_up»…) o ya como emoji. Un nombre que no está en la
 * tabla, o un emoji que no está en el selector, no se importa (sale en los avisos del informe).
 */
final class ReactionEmoji
{
    /** @var array<string, string> */
    public const array SHORTCODES = [
        '+1' => '👍', 'thumbsup' => '👍', 'thumbs_up' => '👍', 'like' => '👍',
        '-1' => '👎', 'thumbsdown' => '👎', 'thumbs_down' => '👎',
        'heart' => '❤️', 'red_heart' => '❤️', 'hearts' => '❤️',
        'orange_heart' => '🧡', 'yellow_heart' => '💛', 'green_heart' => '💚', 'blue_heart' => '💙',
        'purple_heart' => '💜', 'black_heart' => '🖤', 'white_heart' => '🤍', 'brown_heart' => '🤎',
        'broken_heart' => '💔', 'two_hearts' => '💕', 'sparkling_heart' => '💖', 'heartpulse' => '💗',
        'heart_eyes' => '😍', 'smiling_face_with_heart_eyes' => '😍', 'smiling_face_with_hearts' => '🥰',
        'kissing_heart' => '😘', 'face_blowing_a_kiss' => '😘',
        'joy' => '😂', 'face_with_tears_of_joy' => '😂', 'rofl' => '🤣', 'rolling_on_the_floor_laughing' => '🤣',
        'smile' => '😄', 'smiley' => '😃', 'grinning' => '😀', 'grin' => '😁', 'laughing' => '😆', 'satisfied' => '😆',
        'sweat_smile' => '😅', 'slightly_smiling_face' => '🙂', 'upside_down_face' => '🙃', 'wink' => '😉',
        'blush' => '😊', 'innocent' => '😇', 'relaxed' => '☺️', 'yum' => '😋', 'sunglasses' => '😎',
        'nerd_face' => '🤓', 'smirk' => '😏', 'star_struck' => '🤩', 'partying_face' => '🥳',
        'hugs' => '🤗', 'hugging_face' => '🤗', 'thinking' => '🤔', 'thinking_face' => '🤔',
        'face_with_monocle' => '🧐', 'raised_eyebrow' => '🤨', 'face_with_raised_eyebrow' => '🤨',
        'neutral_face' => '😐', 'expressionless' => '😑', 'no_mouth' => '😶', 'roll_eyes' => '🙄',
        'face_with_rolling_eyes' => '🙄', 'grimacing' => '😬', 'relieved' => '😌', 'pensive' => '😔',
        'sleepy' => '😪', 'sleeping' => '😴', 'mask' => '😷', 'exploding_head' => '🤯', 'cowboy_hat_face' => '🤠',
        'confused' => '😕', 'worried' => '😟', 'slightly_frowning_face' => '🙁', 'open_mouth' => '😮',
        'hushed' => '😯', 'astonished' => '😲', 'flushed' => '😳', 'pleading_face' => '🥺',
        'face_holding_back_tears' => '🥹', 'cry' => '😢', 'sob' => '😭', 'scream' => '😱', 'fearful' => '😨',
        'cold_sweat' => '😰', 'disappointed' => '😞', 'sweat' => '😓', 'weary' => '😩', 'tired_face' => '😫',
        'triumph' => '😤', 'rage' => '😡', 'pout' => '😡', 'angry' => '😠', 'skull' => '💀', 'poop' => '💩',
        'hankey' => '💩', 'clown_face' => '🤡', 'ghost' => '👻', 'alien' => '👽', 'robot' => '🤖',
        'see_no_evil' => '🙈', 'hear_no_evil' => '🙉', 'speak_no_evil' => '🙊', 'melting_face' => '🫠',
        'saluting_face' => '🫡', 'shushing_face' => '🤫', 'face_with_hand_over_mouth' => '🤭', 'zany_face' => '🤪',
        'stuck_out_tongue' => '😛', 'stuck_out_tongue_winking_eye' => '😜', 'money_mouth_face' => '🤑',
        'clap' => '👏', 'clapping_hands' => '👏', 'raised_hands' => '🙌', 'pray' => '🙏', 'folded_hands' => '🙏',
        'ok_hand' => '👌', 'wave' => '👋', 'muscle' => '💪', 'v' => '✌️', 'victory_hand' => '✌️',
        'crossed_fingers' => '🤞', 'metal' => '🤘', 'call_me_hand' => '🤙', 'point_up' => '☝️',
        'point_right' => '👉', 'point_left' => '👈', 'point_down' => '👇', 'raised_hand' => '✋', 'hand' => '✋',
        'fist' => '✊', 'punch' => '👊', 'facepunch' => '👊', 'handshake' => '🤝', 'writing_hand' => '✍️',
        'open_hands' => '👐', 'palms_up_together' => '🤲', 'eyes' => '👀', 'eye' => '👁️', 'brain' => '🧠',
        'tada' => '🎉', 'party_popper' => '🎉', 'confetti_ball' => '🎊', 'balloon' => '🎈', 'gift' => '🎁',
        'fire' => '🔥', 'rocket' => '🚀', 'sparkles' => '✨', 'star' => '⭐', 'star2' => '🌟', 'glowing_star' => '🌟',
        'zap' => '⚡', 'high_voltage' => '⚡', 'boom' => '💥', 'collision' => '💥', '100' => '💯', 'hundred_points' => '💯',
        'bulb' => '💡', 'light_bulb' => '💡', 'trophy' => '🏆', 'medal' => '🏅', 'sports_medal' => '🏅',
        'first_place_medal' => '🥇', 'crown' => '👑', 'gem' => '💎', 'moneybag' => '💰', 'money_with_wings' => '💸',
        'chart_with_upwards_trend' => '📈', 'chart_increasing' => '📈', 'dart' => '🎯', 'direct_hit' => '🎯',
        'white_check_mark' => '✅', 'check_mark_button' => '✅', 'heavy_check_mark' => '✔️', 'check_mark' => '✔️',
        'ballot_box_with_check' => '☑️', 'x' => '❌', 'cross_mark' => '❌', 'negative_squared_cross_mark' => '❎',
        'warning' => '⚠️', 'no_entry' => '⛔', 'no_entry_sign' => '🚫', 'question' => '❓', 'exclamation' => '❗',
        'grey_question' => '❔', 'bangbang' => '‼️', 'heavy_plus_sign' => '➕', 'heavy_minus_sign' => '➖',
        'arrow_right' => '➡️', 'arrow_up' => '⬆️', 'arrow_down' => '⬇️', 'repeat' => '🔁', 'recycle' => '♻️',
        'hourglass' => '⌛', 'hourglass_flowing_sand' => '⏳', 'alarm_clock' => '⏰', 'stopwatch' => '⏱️',
        'calendar' => '📆', 'date' => '📅', 'memo' => '📝', 'pencil' => '📝', 'pencil2' => '✏️', 'pushpin' => '📌',
        'round_pushpin' => '📍', 'paperclip' => '📎', 'link' => '🔗', 'lock' => '🔒', 'unlock' => '🔓', 'key' => '🔑',
        'bell' => '🔔', 'mega' => '📣', 'loudspeaker' => '📢', 'speech_balloon' => '💬', 'thought_balloon' => '💭',
        'mag' => '🔍', 'mag_right' => '🔎', 'checkered_flag' => '🏁', 'triangular_flag_on_post' => '🚩',
        'coffee' => '☕', 'hot_beverage' => '☕', 'beer' => '🍺', 'beers' => '🍻', 'clinking_glasses' => '🥂',
        'champagne' => '🍾', 'wine_glass' => '🍷', 'pizza' => '🍕', 'cake' => '🍰', 'birthday' => '🎂',
        'doughnut' => '🍩', 'cookie' => '🍪', 'popcorn' => '🍿', 'avocado' => '🥑', 'tangerine' => '🍊',
        'orange' => '🍊', 'lemon' => '🍋', 'apple' => '🍎', 'banana' => '🍌', 'watermelon' => '🍉',
        'unicorn' => '🦄', 'unicorn_face' => '🦄', 'dog' => '🐶', 'cat' => '🐱', 'monkey_face' => '🐵',
        'rooster' => '🐓', 'chicken' => '🐔', 'turtle' => '🐢', 'snail' => '🐌', 'sunny' => '☀️', 'sun' => '☀️',
        'rainbow' => '🌈', 'snowflake' => '❄️', 'umbrella' => '☂️', 'cloud' => '☁️', 'zzz' => '💤',
        'muscle_tone1' => '💪🏻', 'clap_tone1' => '👏🏻', '+1_tone1' => '👍🏻', 'pray_tone1' => '🙏🏻',
        'computer' => '💻', 'iphone' => '📱', 'camera' => '📷', 'movie_camera' => '🎥', 'art' => '🎨',
        'musical_note' => '🎵', 'notes' => '🎶', 'soccer' => '⚽', 'muscle_tone2' => '💪🏼',
    ];

    public static function of(string $reaction): ?string
    {
        $reaction = trim($reaction);
        $key = mb_strtolower(trim($reaction, ':'));
        $emoji = self::SHORTCODES[$key] ?? $reaction;

        return $emoji !== '' && mb_strlen($emoji) <= 16 && EmojiCatalog::contains($emoji) ? $emoji : null;
    }
}
