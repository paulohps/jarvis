package app.jarvis.android.realtime

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.BroadcastAuthRequest
import app.jarvis.android.network.BroadcastAuthorization
import com.pusher.client.AuthorizationFailureException
import com.pusher.client.ChannelAuthorizer
import kotlinx.coroutines.runBlocking

/**
 * Signs a private Reverb channel through `POST /api/v1/broadcasting/auth`
 * with the device's Bearer token (the web's session cookie does not exist in
 * the app). The Pusher client calls it on its own thread and reads the JSON
 * answer (`{"auth": "…"}`).
 */
class ApiChannelAuthorizer(private val client: ApiClient) : ChannelAuthorizer {
    override fun authorize(channelName: String, socketId: String): String = runBlocking {
        try {
            val authorization = client.call { broadcastingAuth(BroadcastAuthRequest(socketId, channelName)) }

            ApiClient.json.encodeToString(BroadcastAuthorization.serializer(), authorization)
        } catch (exception: ApiException) {
            throw AuthorizationFailureException(exception)
        }
    }
}
