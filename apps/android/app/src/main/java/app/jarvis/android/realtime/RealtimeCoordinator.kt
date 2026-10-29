package app.jarvis.android.realtime

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import app.jarvis.android.app.AppConfiguration
import app.jarvis.android.app.SessionListener
import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.NotificationCreatedEvent
import app.jarvis.android.network.User
import app.jarvis.android.network.WhatsappChatPresenceEvent
import app.jarvis.android.network.WhatsappChatUpdatedEvent
import com.pusher.client.Pusher
import com.pusher.client.PusherOptions
import com.pusher.client.channel.PrivateChannelEventListener
import com.pusher.client.channel.PusherEvent
import com.pusher.client.connection.ConnectionEventListener
import com.pusher.client.connection.ConnectionState
import com.pusher.client.connection.ConnectionStateChange
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.channels.awaitClose
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.callbackFlow
import kotlinx.coroutines.launch

/**
 * The Reverb connection of the signed-in user (US-5.1), over the Pusher
 * protocol: their private channel (`private-App.Models.User.{id}`, signed with
 * the Bearer token) feeds the notification list and badge while the app is
 * open. The client reconnects with backoff by itself, and right away when the
 * network comes back. In the background the socket is closed.
 *
 * The same channel carries the WhatsApp chats' events (a chat got a
 * message, who is typing), handed to [onWhatsappEvent] (`WhatsappLive`).
 *
 * A screen can listen to another private channel while it is shown ([events]),
 * e.g. a shopping list's `private-shopping-lists.{id}` (US-8.2).
 */
class RealtimeCoordinator(
    context: Context,
    configuration: AppConfiguration,
    client: ApiClient,
    private val scope: CoroutineScope,
    private val onNotificationCreated: suspend (NotificationCreatedEvent) -> Unit,
    private val onWhatsappEvent: (name: String, data: String) -> Unit = { _, _ -> },
) : SessionListener {
    private val pusher = Pusher(
        configuration.reverbAppKey,
        PusherOptions()
            .setHost(configuration.reverbHost)
            .setWsPort(configuration.reverbPort)
            .setWssPort(configuration.reverbPort)
            .setUseTLS(configuration.reverbUseTls)
            .setChannelAuthorizer(ApiChannelAuthorizer(client))
            .setMaxReconnectGapInSeconds(30),
    )

    private val mutableIsConnected = MutableStateFlow(false)
    val isConnected: StateFlow<Boolean> = mutableIsConnected.asStateFlow()

    private var userChannel: String? = null
    private var shouldBeConnected = false

    /** The screens listening to other channels, by channel (main thread only). */
    private val channelListeners = mutableMapOf<String, MutableSet<(String) -> Unit>>()

    private val connectionListener = object : ConnectionEventListener {
        override fun onConnectionStateChange(change: ConnectionStateChange) {
            mutableIsConnected.value = change.currentState == ConnectionState.CONNECTED
        }

        override fun onError(message: String?, code: String?, e: Exception?) {
            // The client retries by itself; the network callback and the foreground retry after it gives up.
        }
    }

    private val notificationListener = object : PrivateChannelEventListener {
        override fun onEvent(event: PusherEvent) {
            if (event.eventName != NotificationCreatedEvent.NAME) {
                val name = event.eventName
                val data = event.data

                scope.launch { onWhatsappEvent(name, data) }

                return
            }

            val created = runCatching { ApiClient.json.decodeFromString<NotificationCreatedEvent>(event.data) }.getOrNull() ?: return

            scope.launch { onNotificationCreated(created) }
        }

        override fun onSubscriptionSucceeded(channelName: String) = Unit

        override fun onAuthenticationFailure(message: String?, e: Exception?) = Unit
    }

    init {
        context.getSystemService(ConnectivityManager::class.java).registerDefaultNetworkCallback(
            object : ConnectivityManager.NetworkCallback() {
                override fun onAvailable(network: Network) {
                    scope.launch { connectIfNeeded() }
                }
            },
        )
    }

    override fun onUserChanged(user: User, isNewSession: Boolean) {
        scope.launch { start(user.broadcastChannel) }
    }

    override suspend fun onForeground(user: User) {
        start(user.broadcastChannel)
    }

    override suspend fun onBackground() {
        shouldBeConnected = false
        pusher.disconnect()
    }

    override suspend fun onSessionEnded() {
        shouldBeConnected = false
        userChannel?.let(pusher::unsubscribe)
        userChannel = null
        channelListeners.keys.forEach(::unsubscribe)
        channelListeners.clear()
        pusher.disconnect()
    }

    /**
     * The names of the events of a private channel while the flow is collected,
     * e.g. `shopping-list.changed` on `private-shopping-lists.{id}`. The channel
     * is left when its last collector goes away.
     *
     * @param eventNames the events to listen to.
     */
    fun events(channel: String, vararg eventNames: String): Flow<String> = callbackFlow {
        val listener: (String) -> Unit = { name -> trySend(name) }

        scope.launch {
            val listeners = channelListeners.getOrPut(channel) { mutableSetOf() }
            val isFirstListener = listeners.isEmpty()
            listeners += listener

            if (isFirstListener) {
                subscribe(channel, eventNames)
            }
        }

        awaitClose {
            scope.launch {
                val listeners = channelListeners[channel] ?: return@launch
                listeners -= listener

                if (listeners.isEmpty()) {
                    channelListeners -= channel
                    unsubscribe(channel)
                }
            }
        }
    }

    private fun subscribe(channel: String, eventNames: Array<out String>) {
        val listener = object : PrivateChannelEventListener {
            override fun onEvent(event: PusherEvent) {
                val name = event.eventName

                scope.launch { channelListeners[channel]?.toList()?.forEach { it(name) } }
            }

            override fun onSubscriptionSucceeded(channelName: String) = Unit

            override fun onAuthenticationFailure(message: String?, e: Exception?) = Unit
        }

        // Left and opened again before the client forgot it: subscribed again from scratch.
        if (pusher.getPrivateChannel(channel) != null) {
            unsubscribe(channel)
        }

        try {
            pusher.subscribePrivate(channel, listener, *eventNames)
        } catch (_: IllegalArgumentException) {
            // Already subscribed: the client keeps the channel.
        }

        connectIfNeeded()
    }

    private fun unsubscribe(channel: String) {
        try {
            pusher.unsubscribe(channel)
        } catch (_: IllegalArgumentException) {
            // Not subscribed.
        }
    }

    private fun start(channel: String?) {
        if (channel != null && channel != userChannel) {
            userChannel?.let(pusher::unsubscribe)
            userChannel = channel
            pusher.subscribePrivate(channel, notificationListener, *USER_EVENTS)
        }

        shouldBeConnected = true
        connectIfNeeded()
    }

    private fun connectIfNeeded() {
        if (shouldBeConnected && pusher.connection.state == ConnectionState.DISCONNECTED) {
            pusher.connect(connectionListener, ConnectionState.ALL)
        }
    }

    companion object {
        /** The events of the user's channel. */
        private val USER_EVENTS = arrayOf(NotificationCreatedEvent.NAME, WhatsappChatUpdatedEvent.NAME, WhatsappChatPresenceEvent.NAME)
    }
}
