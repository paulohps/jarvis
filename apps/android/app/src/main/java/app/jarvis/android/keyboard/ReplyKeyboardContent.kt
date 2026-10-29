package app.jarvis.android.keyboard

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Autorenew
import androidx.compose.material.icons.filled.Keyboard
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.network.WhatsappChat
import app.jarvis.android.network.WhatsappChatMessage

/**
 * The reply keyboard: a bar (back, title, previous keyboard) over the chats,
 * the messages of a chat or the drafted answer.
 *
 * @param typedText the text before the cursor in the field, read when a draft is asked for.
 * @param insert puts the draft in the field, replacing the typed instruction.
 */
@Composable
fun ReplyKeyboardContent(
    store: ReplyKeyboardStore,
    typedText: () -> String?,
    insert: (text: String, replacingTyped: String?) -> Unit,
    switchKeyboard: () -> Unit,
) {
    val state by store.state.collectAsState()

    LaunchedEffect(Unit) { store.loadChats() }

    Surface(color = MaterialTheme.colorScheme.surfaceContainer, modifier = Modifier.fillMaxWidth().height(300.dp)) {
        Column {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.fillMaxWidth().height(48.dp).padding(horizontal = 4.dp),
            ) {
                if (state.screen != ReplyKeyboardScreen.Chats) {
                    IconButton(onClick = store::back) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.keyboard_back))
                    }
                }

                Text(
                    text = when (val screen = state.screen) {
                        ReplyKeyboardScreen.Chats -> stringResource(R.string.keyboard_title)
                        is ReplyKeyboardScreen.Messages -> screen.chat.name
                        is ReplyKeyboardScreen.Draft -> screen.chat.name
                    },
                    style = MaterialTheme.typography.titleSmall,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f).padding(horizontal = 8.dp),
                )

                if (state.screen == ReplyKeyboardScreen.Chats) {
                    IconButton(onClick = store::loadChats) {
                        Icon(Icons.Filled.Refresh, contentDescription = stringResource(R.string.keyboard_refresh))
                    }
                }

                IconButton(onClick = switchKeyboard) {
                    Icon(Icons.Filled.Keyboard, contentDescription = stringResource(R.string.keyboard_switch))
                }
            }

            HorizontalDivider()

            Box(modifier = Modifier.fillMaxSize()) {
                val problem = state.problem

                when {
                    problem != null -> ProblemMessage(problem)
                    else -> when (val screen = state.screen) {
                        ReplyKeyboardScreen.Chats -> ChatList(state.chats, state.isLoading, store::open)
                        is ReplyKeyboardScreen.Messages -> MessageList(state.messages, state.isLoading) {
                            store.draftReply(screen.chat, it, typedText())
                        }
                        is ReplyKeyboardScreen.Draft -> DraftPanel(
                            message = screen.message,
                            instruction = state.instruction,
                            draft = state.draft,
                            onRegenerate = { store.regenerate(typedText()) },
                            onInsert = {
                                state.draft?.let { draft ->
                                    insert(draft, state.instruction)
                                    store.loadChats()
                                }
                            },
                        )
                    }
                }

                if (state.isLoading && state.screen !is ReplyKeyboardScreen.Draft) {
                    CircularProgressIndicator(modifier = Modifier.align(Alignment.Center))
                }
            }
        }
    }
}

@Composable
private fun ChatList(chats: List<WhatsappChat>, isLoading: Boolean, onOpen: (WhatsappChat) -> Unit) {
    if (chats.isEmpty() && !isLoading) {
        CenteredText(stringResource(R.string.keyboard_no_messages))

        return
    }

    LazyColumn {
        items(chats, key = { it.friendlyId }) { chat ->
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.fillMaxWidth().clickable { onOpen(chat) }.padding(horizontal = 12.dp, vertical = 8.dp),
            ) {
                Box(
                    modifier = Modifier.size(8.dp).background(
                        if (chat.awaitingReply) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceContainer,
                        CircleShape,
                    ),
                )
                Spacer(Modifier.size(8.dp))
                Column {
                    Text(chat.name, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(
                        chat.lastReceivedMessage?.let { preview(it) }.orEmpty(),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        }
    }
}

@Composable
private fun MessageList(messages: List<WhatsappChatMessage>, isLoading: Boolean, onPick: (WhatsappChatMessage) -> Unit) {
    Column {
        LazyColumn(modifier = Modifier.weight(1f), reverseLayout = true) {
            items(messages, key = { it.friendlyId }) { message ->
                Row(
                    horizontalArrangement = if (message.fromMe) Arrangement.End else Arrangement.Start,
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 3.dp),
                ) {
                    Text(
                        text = preview(message),
                        style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier
                            .widthIn(max = 280.dp)
                            .background(
                                if (message.fromMe) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface,
                                RoundedCornerShape(10.dp),
                            )
                            .clickable(enabled = !message.fromMe) { onPick(message) }
                            .padding(8.dp),
                    )
                }
            }
        }

        if (!isLoading) {
            Text(
                stringResource(R.string.keyboard_pick_hint),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(horizontal = 12.dp, vertical = 4.dp),
            )
        }
    }
}

@Composable
private fun DraftPanel(
    message: WhatsappChatMessage,
    instruction: String?,
    draft: String?,
    onRegenerate: () -> Unit,
    onInsert: () -> Unit,
) {
    Column(modifier = Modifier.fillMaxSize().padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(preview(message), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 2, overflow = TextOverflow.Ellipsis)

        instruction?.let {
            Text("✎ $it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }

        Box(
            modifier = Modifier
                .weight(1f)
                .fillMaxWidth()
                .background(MaterialTheme.colorScheme.surface, RoundedCornerShape(10.dp))
                .padding(10.dp),
        ) {
            if (draft == null) {
                Column(modifier = Modifier.align(Alignment.Center), horizontalAlignment = Alignment.CenterHorizontally) {
                    CircularProgressIndicator()
                    Text(stringResource(R.string.keyboard_writing), style = MaterialTheme.typography.bodySmall)
                }
            } else {
                Text(draft, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.verticalScroll(rememberScrollState()))
            }
        }

        Row(horizontalArrangement = Arrangement.SpaceBetween, modifier = Modifier.fillMaxWidth()) {
            OutlinedButton(onClick = onRegenerate) {
                Icon(Icons.Filled.Autorenew, contentDescription = null)
                Spacer(Modifier.size(4.dp))
                Text(stringResource(R.string.keyboard_another))
            }
            Button(onClick = onInsert, enabled = draft != null) {
                Text(stringResource(R.string.keyboard_insert))
            }
        }
    }
}

@Composable
private fun ProblemMessage(problem: ReplyKeyboardProblem) {
    CenteredText(
        when (problem) {
            ReplyKeyboardProblem.SignedOut -> stringResource(R.string.keyboard_signed_out)
            is ReplyKeyboardProblem.Failed -> problem.message ?: stringResource(R.string.keyboard_failed)
        },
    )
}

@Composable
private fun CenteredText(text: String) {
    Box(modifier = Modifier.fillMaxSize().padding(16.dp), contentAlignment = Alignment.Center) {
        Text(text, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center)
    }
}

@Composable
private fun preview(message: WhatsappChatMessage): String = message.body?.takeIf { it.isNotEmpty() } ?: when (message.type) {
    "audioMessage" -> stringResource(R.string.keyboard_audio)
    "imageMessage" -> stringResource(R.string.keyboard_photo)
    "videoMessage" -> stringResource(R.string.keyboard_video)
    "stickerMessage" -> stringResource(R.string.keyboard_sticker)
    else -> stringResource(R.string.keyboard_attachment)
}
