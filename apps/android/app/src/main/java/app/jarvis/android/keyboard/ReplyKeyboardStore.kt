package app.jarvis.android.keyboard

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappChat
import app.jarvis.android.network.WhatsappChatMessage
import app.jarvis.android.network.WhatsappReplyRequest
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

sealed interface ReplyKeyboardScreen {
    data object Chats : ReplyKeyboardScreen

    data class Messages(val chat: WhatsappChat) : ReplyKeyboardScreen

    data class Draft(val chat: WhatsappChat, val message: WhatsappChatMessage) : ReplyKeyboardScreen
}

sealed interface ReplyKeyboardProblem {
    data object SignedOut : ReplyKeyboardProblem

    data class Failed(val message: String?) : ReplyKeyboardProblem
}

data class ReplyKeyboardState(
    val screen: ReplyKeyboardScreen = ReplyKeyboardScreen.Chats,
    val chats: List<WhatsappChat> = emptyList(),
    val messages: List<WhatsappChatMessage> = emptyList(),
    val draft: String? = null,
    /** What was typed in the field when the draft was asked for: the instruction, replaced by the draft. */
    val instruction: String? = null,
    val isLoading: Boolean = false,
    val problem: ReplyKeyboardProblem? = null,
)

/**
 * What the reply keyboard shows and does: the chats where a contact wrote
 * (latest received first), the messages of the picked chat and the answer
 * the server drafts for the picked message. The AI is called only when a
 * message is picked or "Another one" is tapped; nothing is sent from here.
 *
 * @param sessionReady waits until the saved token is read.
 */
class ReplyKeyboardStore(
    private val client: ApiClient,
    private val scope: CoroutineScope,
    private val sessionReady: suspend () -> Unit = {},
) {
    private val mutableState = MutableStateFlow(ReplyKeyboardState())
    val state: StateFlow<ReplyKeyboardState> = mutableState.asStateFlow()

    private var job: Job? = null

    fun loadChats() {
        mutableState.update { it.copy(screen = ReplyKeyboardScreen.Chats, draft = null) }

        run {
            val page = client.call { whatsappChats() }
            mutableState.update { it.copy(chats = page.data) }
        }
    }

    fun open(chat: WhatsappChat) {
        mutableState.update { it.copy(screen = ReplyKeyboardScreen.Messages(chat), messages = emptyList()) }

        run {
            val messages = client.call { whatsappChatMessages(chat.friendlyId) }.data
            mutableState.update { it.copy(messages = messages) }
        }
    }

    /** Drafts the answer to the message, following what was typed in the field. */
    fun draftReply(chat: WhatsappChat, message: WhatsappChatMessage, typed: String?) {
        val request = WhatsappReplyRequest.of(typed)
        mutableState.update { it.copy(screen = ReplyKeyboardScreen.Draft(chat, message), draft = null, instruction = request.instruction) }

        run {
            val draft = client.call { draftWhatsappReply(message.friendlyId, request) }.text
            mutableState.update { it.copy(draft = draft) }
        }
    }

    fun regenerate(typed: String?) {
        val screen = state.value.screen as? ReplyKeyboardScreen.Draft ?: return

        draftReply(screen.chat, screen.message, typed?.takeIf { it.isNotBlank() } ?: state.value.instruction)
    }

    fun back() {
        when (val screen = state.value.screen) {
            ReplyKeyboardScreen.Chats -> Unit
            is ReplyKeyboardScreen.Messages -> loadChats()
            is ReplyKeyboardScreen.Draft -> open(screen.chat)
        }
    }

    private fun run(work: suspend () -> Unit) {
        job?.cancel()
        mutableState.update { it.copy(isLoading = true, problem = null) }

        job = scope.launch {
            try {
                sessionReady()

                if (client.tokenStore.token() == null) {
                    mutableState.update { it.copy(problem = ReplyKeyboardProblem.SignedOut) }
                } else {
                    work()
                }
            } catch (exception: CancellationException) {
                throw exception
            } catch (exception: ApiException.Unauthenticated) {
                mutableState.update { it.copy(problem = ReplyKeyboardProblem.SignedOut) }
            } catch (exception: ApiException) {
                mutableState.update { it.copy(problem = ReplyKeyboardProblem.Failed(exception.displayMessage)) }
            } finally {
                mutableState.update { it.copy(isLoading = false) }
            }
        }
    }
}
