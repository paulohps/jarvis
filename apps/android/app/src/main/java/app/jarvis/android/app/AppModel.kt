package app.jarvis.android.app

import app.jarvis.android.auth.AuthService
import app.jarvis.android.i18n.AppLanguage
import app.jarvis.android.i18n.LanguagePreference
import app.jarvis.android.navigation.AppRoute
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.DeepLinkRouter
import app.jarvis.android.navigation.NavigationBuilder
import app.jarvis.android.navigation.Router
import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.PreferencesRequest
import app.jarvis.android.network.SessionEvent
import app.jarvis.android.network.User
import app.jarvis.android.network.WorkspacePreferenceInput
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/** Why the login is shown again. */
enum class SignedOutNotice {
    SessionExpired,
}

/** Where the app is: opening, at the login, or signed in as a user. */
sealed interface SessionState {
    data object Launching : SessionState

    data class SignedOut(val notice: SignedOutNotice? = null) : SessionState

    data class SignedIn(val user: User) : SessionState
}

/**
 * The state of the app shell: the session, the language and the user from
 * `/me`. Any 401 of any call ([SessionEvent.SessionExpired]) takes the app
 * back to the login (US-1.5); the language follows the user's preference read
 * from `/me` (US-3.2, US-12.2).
 */
class AppModel(
    private val scope: CoroutineScope,
    val client: ApiClient,
    val auth: AuthService,
    private val languagePreference: LanguagePreference,
    private val userCache: UserCache,
    private val deepLinks: DeepLinkRouter = DeepLinkRouter(client.serverUrl.toString()),
) {
    private val mutableState = MutableStateFlow<SessionState>(SessionState.Launching)
    val state: StateFlow<SessionState> = mutableState.asStateFlow()

    private val listeners = mutableListOf<SessionListener>()
    private var sessionEventsJob: Job? = null

    /** A deep link opened before the session was ready (a push that launched the app). */
    private var pendingRoute: AppRoute? = null

    private val mutableWebLinks = MutableSharedFlow<String>(extraBufferCapacity = 4)

    /** The links of pages that exist only on the web (an invoice), for the activity to open in the browser. */
    val webLinks: SharedFlow<String> = mutableWebLinks.asSharedFlow()

    val router = Router()

    private val mutableTabs = MutableStateFlow<List<AppTab>>(emptyList())

    /** The tabs of the signed-in user, from the modules of `/me` (US-11.1). */
    val tabs: StateFlow<List<AppTab>> = mutableTabs.asStateFlow()

    val user: User?
        get() = (state.value as? SessionState.SignedIn)?.user

    val language: AppLanguage
        get() = languagePreference.current

    /** Opens the session kept on disk, if any, and starts listening to the client. */
    suspend fun start() {
        observeSessionEvents()

        if (!auth.isSignedIn) {
            mutableState.value = SessionState.SignedOut()

            return
        }

        userCache.load()?.let { apply(it) }
        refreshUser()

        if (state.value == SessionState.Launching) {
            mutableState.value = SessionState.SignedOut()
        }
    }

    /** Reads `/me` again: the modules, the language and the timezone may have changed. */
    suspend fun refreshUser() {
        if (!auth.isSignedIn) {
            return
        }

        try {
            apply(client.call { me() }.data)
        } catch (_: ApiException) {
            // A 401 already took the app to the login; other failures keep what is shown.
        }
    }

    /** Back to the foreground: `/me` again (a module switched off goes away, US-11.1). */
    suspend fun didBecomeActive() {
        refreshUser()
        user?.let { user -> listeners.forEach { it.onForeground(user) } }
    }

    /** The app went to the background. */
    suspend fun didEnterBackground() {
        listeners.forEach { it.onBackground() }
    }

    /** After the login screen got a token. */
    suspend fun didSignIn(user: User) {
        apply(user)
    }

    suspend fun signOut() {
        auth.logout()
        endSession(notice = null)
    }

    /**
     * Opens a deep link (US-5.2): a push's `deep_link`, a notification's link or
     * a `jarvis://` URL. Before the session is ready it waits for it.
     *
     * @return `false` when the link leads nowhere in the app.
     */
    fun open(link: String?): Boolean {
        val route = deepLinks.route(link) ?: return false

        if (route is AppRoute.Web) {
            return mutableWebLinks.tryEmit(route.url)
        }

        if (user == null) {
            pendingRoute = route

            return true
        }

        return router.open(route, tabs.value)
    }

    /** Saves the language on the server (US-3.2); the web follows it too. */
    suspend fun updateLanguage(language: AppLanguage) {
        apply(client.call { updatePreferences(PreferencesRequest(locale = language.apiLocale)) }.data)
    }

    /**
     * Saves the user's own color for a workspace or makes it their default,
     * then reads `/me` again: making one the default unsets the previous.
     */
    suspend fun updateWorkspacePreference(workspaceId: String, input: WorkspacePreferenceInput) {
        client.call { updateWorkspacePreference(workspaceId, input.body) }
        refreshUser()
    }

    private suspend fun apply(user: User) {
        val isNewSession = this.user == null

        userCache.save(user)
        languagePreference.apply(user.locale)
        mutableTabs.value = NavigationBuilder.tabs(user.modules)

        if (isNewSession) {
            router.reset(tabs.value)
        } else {
            router.keepSelection(tabs.value)
        }

        mutableState.value = SessionState.SignedIn(user)
        onUserChanged(user, isNewSession)

        pendingRoute?.let {
            pendingRoute = null
            router.open(it, tabs.value)
        }
    }

    /** What the rest of the shell does with a new `/me` (tabs, push, realtime). */
    private fun onUserChanged(user: User, isNewSession: Boolean) {
        listeners.forEach { it.onUserChanged(user, isNewSession) }
    }

    private suspend fun endSession(notice: SignedOutNotice?) {
        listeners.forEach { it.onSessionEnded() }
        userCache.clear()
        languagePreference.reset()
        pendingRoute = null
        mutableTabs.value = emptyList()
        mutableState.value = SessionState.SignedOut(notice)
    }

    /** Lets a part of the shell follow the session. */
    fun addListener(listener: SessionListener) {
        listeners += listener
    }

    private fun observeSessionEvents() {
        if (sessionEventsJob != null) {
            return
        }

        sessionEventsJob = scope.launch {
            client.sessionEvents.collect { event ->
                when (event) {
                    SessionEvent.SessionExpired -> if (state.value !is SessionState.SignedOut) {
                        endSession(SignedOutNotice.SessionExpired)
                    }

                    SessionEvent.AccessDenied -> refreshUser()
                }
            }
        }
    }
}

/** A part of the shell that follows the session (push, realtime, notifications). */
interface SessionListener {
    fun onUserChanged(user: User, isNewSession: Boolean) {}

    suspend fun onSessionEnded() {}

    suspend fun onForeground(user: User) {}

    suspend fun onBackground() {}
}
