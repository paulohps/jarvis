package app.jarvis.android.ui.whatsapp

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import app.jarvis.android.R
import app.jarvis.android.network.WhatsappReaction

/** A group of the emoji picker: its tab, its name and its emojis, the ones people use most (as the web's). */
internal class EmojiGroup(val key: String, val icon: String, val label: Int, val emojis: List<String>)

internal val EMOJI_GROUPS = listOf(
    EmojiGroup(
        "smileys", "😀", R.string.whatsapp_emoji_smileys,
        "😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😙 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 😮‍💨 🤥 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🥵 🥶 🥴 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 🥹 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠 🤬 😈 👿 💀 💩 🤡 👻 👽 🤖 😺 😸 😹 😻 😼 😽 🙀 😿 😾".split(" "),
    ),
    EmojiGroup(
        "gestures", "👍", R.string.whatsapp_emoji_gestures,
        "👍 👎 👌 🤌 🤏 ✌️ 🤞 🫰 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 🫡 👏 🙌 🫶 👐 🤲 🤝 🙏 ✍️ 💪 🦾 🧠 👀 👁️ 👅 👄 💋 🫂 👶 🧒 👦 👧 🧑 👨 👩 🧓 👴 👵 🙋 🙆 🙅 🤷 🤦 💁 🙇 🧑‍💻 👨‍👩‍👧 👫".split(" "),
    ),
    EmojiGroup(
        "hearts", "❤️", R.string.whatsapp_emoji_hearts,
        "❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❤️‍🔥 ❤️‍🩹 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ♥️ 💯 💢 💥 💫 💦 💨 🕳️ 💬 🗨️ 💭 💤 ✨ ⭐ 🌟 🔥 🎉 🎊 🎈 🎁 🏆 🥇 ✅ ❌ ❗ ❓ ⚠️ 🚫 🔔 📌 📍".split(" "),
    ),
    EmojiGroup(
        "nature", "🐶", R.string.whatsapp_emoji_nature,
        "🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐔 🐧 🐦 🐤 🦆 🦉 🐴 🦄 🐝 🦋 🐌 🐞 🐢 🐍 🐙 🐠 🐬 🐳 🦈 🐊 🐘 🦒 🐕 🐈 🌵 🌲 🌳 🌴 🌱 🌿 🍀 🍁 🍂 🌷 🌹 🌺 🌸 🌼 🌻 🌞 🌝 🌙 ⭐ ☀️ ⛅ 🌧️ ⛈️ ❄️ ☃️ 🌈 🌊".split(" "),
    ),
    EmojiGroup(
        "food", "🍔", R.string.whatsapp_emoji_food,
        "🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🫐 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🥑 🥦 🥕 🌽 🌶️ 🥔 🍞 🧀 🥚 🍳 🥓 🥩 🍗 🍖 🌭 🍔 🍟 🍕 🥪 🌮 🌯 🥗 🍝 🍜 🍣 🍱 🍤 🍚 🍦 🍰 🎂 🧁 🍫 🍬 🍭 🍩 🍪 ☕ 🍵 🥤 🧃 🍺 🍻 🍷 🥂 🍾 🍹 🧉".split(" "),
    ),
    EmojiGroup(
        "activities", "⚽", R.string.whatsapp_emoji_activities,
        "⚽ 🏀 🏈 ⚾ 🎾 🏐 🏉 🎱 🏓 🏸 🥊 🥋 ⛳ 🎣 🏊 🏄 🚴 🏃 🧘 🏋️ 🤸 🎮 🎲 🧩 🎯 🎳 🎸 🎹 🥁 🎤 🎧 🎬 🎨 📚 ✈️ 🚗 🚕 🚌 🏍️ 🚲 🚀 ⛵ 🏠 🏡 🏢 🏥 🏫 ⛪ 🏖️ 🏝️ 🗺️ 🎡 🎢".split(" "),
    ),
    EmojiGroup(
        "objects", "💡", R.string.whatsapp_emoji_objects,
        "⌚ 📱 💻 ⌨️ 🖥️ 🖨️ 📷 📸 📹 🎥 📞 ☎️ 📺 📻 ⏰ ⏳ 🔋 🔌 💡 🔦 🕯️ 💸 💵 💰 💳 💎 🔧 🔨 🛠️ 🔑 🗝️ 🔒 🔓 🚪 🛏️ 🛋️ 🚿 🧹 🧺 🧻 🛒 🎒 👓 👔 👕 👖 👗 👟 👠 💍 💄 📦 ✉️ 📧 📝 📅 📆 📎 ✂️ 🗑️ 💊 💉 🩺".split(" "),
    ),
)

/** The emojis picked lately, the latest first, kept on the phone. */
internal object RecentEmojis {
    private const val PREFERENCES = "whatsapp_emoji"

    private const val KEY = "recent"

    const val LIMIT = 24

    fun read(context: Context): List<String> =
        context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE).getString(KEY, null)?.split(" ")?.filter { it.isNotEmpty() }.orEmpty()

    /** @return the recent ones with [emoji] first. */
    fun add(context: Context, emoji: String): List<String> {
        val recent = (listOf(emoji) + read(context).filterNot { it == emoji }).take(LIMIT)
        context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE).edit().putString(KEY, recent.joinToString(" ")).apply()

        return recent
    }
}

/** The emoji picker as WhatsApp's: a tab per group (the recent ones first, once there are some) over the emojis. */
@Composable
internal fun EmojiGrid(onPick: (String) -> Unit, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    var recent by remember { mutableStateOf(RecentEmojis.read(context)) }
    var group by remember { mutableStateOf(if (recent.isEmpty()) EMOJI_GROUPS.first().key else RECENT) }
    val shown = if (group == RECENT) recent else EMOJI_GROUPS.first { it.key == group }.emojis

    Column(modifier.testTag("whatsapp.emoji.grid")) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp), horizontalArrangement = Arrangement.SpaceBetween) {
            val tab: @Composable (String, String, String) -> Unit = { key, icon, label ->
                Box(
                    Modifier
                        .size(36.dp)
                        .clip(CircleShape)
                        .background(if (group == key) MaterialTheme.colorScheme.secondaryContainer else Color.Transparent)
                        .clickable(onClickLabel = label) { group = key }
                        .testTag("whatsapp.emoji.group.$key"),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(icon, fontSize = 18.sp)
                }
            }

            if (recent.isNotEmpty()) {
                tab(RECENT, "🕘", stringResource(R.string.whatsapp_emoji_recent))
            }

            EMOJI_GROUPS.forEach { tab(it.key, it.icon, stringResource(it.label)) }
        }

        LazyVerticalGrid(columns = GridCells.Adaptive(44.dp), modifier = Modifier.fillMaxWidth().weight(1f).padding(horizontal = 4.dp)) {
            items(shown) { emoji ->
                Box(
                    Modifier
                        .size(44.dp)
                        .clip(RoundedCornerShape(8.dp))
                        .clickable {
                            recent = RecentEmojis.add(context, emoji)
                            onPick(emoji)
                        },
                    contentAlignment = Alignment.Center,
                ) {
                    Text(emoji, fontSize = 26.sp)
                }
            }
        }
    }
}

private const val RECENT = "recent"

/** The emoji picker in a sheet, for a reaction outside the quick ones; it closes on a pick. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun EmojiPickerSheet(onPick: (String) -> Unit, onDismiss: () -> Unit) {
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        EmojiGrid(onPick = onPick, modifier = Modifier.fillMaxWidth().height(360.dp).navigationBarsPadding())
    }
}

/**
 * WhatsApp's reaction bar over a picked message: the quick reactions (the
 * user's own one marked; tapping it removes it) and "+" for any emoji.
 */
@Composable
internal fun ReactionBar(mine: String?, onReact: (String) -> Unit, onMore: () -> Unit, modifier: Modifier = Modifier) {
    Surface(shape = RoundedCornerShape(28.dp), shadowElevation = 6.dp, tonalElevation = 2.dp, modifier = modifier.testTag("whatsapp.reaction.bar")) {
        Row(Modifier.padding(horizontal = 6.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(2.dp)) {
            WhatsappChatStore.QUICK_REACTIONS.forEach { emoji ->
                Box(
                    Modifier
                        .size(42.dp)
                        .clip(CircleShape)
                        .background(if (emoji == mine) MaterialTheme.colorScheme.secondaryContainer else Color.Transparent)
                        .clickable { onReact(emoji) }
                        .testTag("whatsapp.reaction.quick.$emoji"),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(emoji, fontSize = 24.sp)
                }
            }

            Box(
                Modifier.size(36.dp).clip(CircleShape).background(MaterialTheme.colorScheme.surfaceContainerHighest).clickable(onClick = onMore).testTag("whatsapp.reaction.more"),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Filled.Add, contentDescription = stringResource(R.string.whatsapp_more_emojis), modifier = Modifier.size(20.dp))
            }
        }
    }
}

/**
 * The reactions of a message under its bubble, overlapping its bottom edge
 * on the bubble's side: each emoji with how many gave it (above one); the
 * user's own is marked, and a tap on it takes it back.
 */
@Composable
internal fun ReactionChips(reactions: List<WhatsappReaction>, fromMe: Boolean, background: Color, onRemoveMine: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().offset(y = (-6).dp).padding(horizontal = 10.dp),
        horizontalArrangement = if (fromMe) Arrangement.End else Arrangement.Start,
    ) {
        Row(
            Modifier
                .clip(RoundedCornerShape(12.dp))
                .background(background)
                .border(1.dp, MaterialTheme.colorScheme.outlineVariant, RoundedCornerShape(12.dp))
                .padding(horizontal = 2.dp, vertical = 1.dp),
            horizontalArrangement = Arrangement.spacedBy(2.dp),
        ) {
            val removeLabel = stringResource(R.string.whatsapp_reaction_remove)

            reactions.forEach { reaction ->
                Row(
                    Modifier
                        .clip(RoundedCornerShape(10.dp))
                        .background(if (reaction.mine) MaterialTheme.colorScheme.secondaryContainer else Color.Transparent)
                        .then(if (reaction.mine) Modifier.clickable(onClickLabel = removeLabel, onClick = onRemoveMine) else Modifier)
                        .padding(horizontal = 5.dp, vertical = 1.dp)
                        .testTag(if (reaction.mine) "whatsapp.reaction.mine" else "whatsapp.reaction"),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(reaction.emoji, fontSize = 15.sp)

                    if (reaction.count > 1) {
                        Text(reaction.count.toString(), style = MaterialTheme.typography.labelSmall, modifier = Modifier.padding(start = 2.dp))
                    }
                }
            }
        }
    }
}
