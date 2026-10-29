package app.jarvis.android.keyboard

import android.inputmethodservice.InputMethodService
import android.view.View
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.platform.ViewCompositionStrategy
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.LifecycleRegistry
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.SavedStateRegistry
import androidx.savedstate.SavedStateRegistryController
import androidx.savedstate.SavedStateRegistryOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import app.jarvis.android.JarvisApplication
import app.jarvis.android.ui.theme.JarvisTheme
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel

/**
 * The reply keyboard (an input method): lists the WhatsApp chats where a
 * contact wrote, drafts the answer to the picked message with the AI and puts
 * it in the field. It runs in the app's process, so it calls the server with
 * the app's own client and token; signed out of the app, it asks to sign in.
 *
 * An input method window has no lifecycle of its own, so the service is the
 * lifecycle and saved-state owner Compose needs.
 */
class ReplyKeyboardService : InputMethodService(), LifecycleOwner, SavedStateRegistryOwner {
    private val lifecycleRegistry = LifecycleRegistry(this)
    private val savedStateController = SavedStateRegistryController.create(this)
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)

    private lateinit var store: ReplyKeyboardStore

    override val lifecycle: Lifecycle get() = lifecycleRegistry

    override val savedStateRegistry: SavedStateRegistry get() = savedStateController.savedStateRegistry

    override fun onCreate() {
        super.onCreate()
        savedStateController.performRestore(null)
        lifecycleRegistry.handleLifecycleEvent(Lifecycle.Event.ON_CREATE)

        val application = application as JarvisApplication
        store = ReplyKeyboardStore(application.container.client, scope) { application.sessionLoaded.join() }
    }

    override fun onCreateInputView(): View {
        window.window?.decorView?.let { decorView ->
            decorView.setViewTreeLifecycleOwner(this)
            decorView.setViewTreeSavedStateRegistryOwner(this)
        }

        return ComposeView(this).apply {
            setViewCompositionStrategy(ViewCompositionStrategy.DisposeOnDetachedFromWindow)
            setContent {
                JarvisTheme {
                    ReplyKeyboardContent(
                        store = store,
                        typedText = ::typedText,
                        insert = ::insert,
                        switchKeyboard = ::switchKeyboard,
                    )
                }
            }
        }
    }

    override fun onStartInputView(info: android.view.inputmethod.EditorInfo?, restarting: Boolean) {
        super.onStartInputView(info, restarting)
        lifecycleRegistry.handleLifecycleEvent(Lifecycle.Event.ON_RESUME)
    }

    override fun onFinishInputView(finishingInput: Boolean) {
        lifecycleRegistry.handleLifecycleEvent(Lifecycle.Event.ON_PAUSE)
        super.onFinishInputView(finishingInput)
    }

    override fun onDestroy() {
        lifecycleRegistry.handleLifecycleEvent(Lifecycle.Event.ON_DESTROY)
        scope.cancel()
        super.onDestroy()
    }

    private fun typedText(): String? = currentInputConnection?.getTextBeforeCursor(MAX_TYPED, 0)?.toString()

    /** Puts the draft in the field; the instruction typed before it is removed first, when still there. */
    private fun insert(text: String, replacingTyped: String?) {
        val connection = currentInputConnection ?: return
        val before = connection.getTextBeforeCursor(MAX_TYPED, 0)?.toString()

        connection.beginBatchEdit()

        if (replacingTyped != null && before != null && before.trim().endsWith(replacingTyped)) {
            connection.deleteSurroundingText(before.length, 0)
        }

        connection.commitText(text, 1)
        connection.endBatchEdit()
    }

    /** Back to the keyboard used before, or the system picker when there is none. */
    private fun switchKeyboard() {
        if (!switchToPreviousInputMethod()) {
            getSystemService(android.view.inputmethod.InputMethodManager::class.java)?.showInputMethodPicker()
        }
    }

    private companion object {
        const val MAX_TYPED = 1000
    }
}
