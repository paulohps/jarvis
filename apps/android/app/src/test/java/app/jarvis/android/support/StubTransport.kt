package app.jarvis.android.support

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.InMemoryTokenStore
import app.jarvis.android.network.TokenStore
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Request
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import okio.Buffer
import java.io.IOException

/**
 * The network of the tests: the last interceptor of the client, it answers
 * the queued responses (in order) and records the requests as they left the
 * client, headers included.
 */
class StubTransport : Interceptor {
    private val responses = ArrayDeque<(Request) -> Response>()

    val requests = mutableListOf<Request>()

    /** The JSON bodies sent, in order. */
    val bodies = mutableListOf<String?>()

    fun enqueue(code: Int, json: String = "") {
        responses += { request ->
            Response.Builder()
                .request(request)
                .protocol(Protocol.HTTP_1_1)
                .code(code)
                .message("HTTP $code")
                .body(json.toResponseBody("application/json".toMediaType()))
                .build()
        }
    }

    /** No answer at all (no network). */
    fun enqueueFailure() {
        responses += { throw IOException("The network is down") }
    }

    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        requests += request
        bodies += request.body?.let { body -> Buffer().also(body::writeTo).readUtf8() }

        val next = responses.removeFirstOrNull() ?: error("No response queued for ${request.method} ${request.url}")

        return next(request)
    }

    /** A client of `https://jarvis.test` on this transport. */
    fun client(
        tokenStore: TokenStore = InMemoryTokenStore("device-token"),
        acceptLanguage: () -> String? = { "en" },
    ) = ApiClient(
        baseUrl = "https://jarvis.test",
        tokenStore = tokenStore,
        acceptLanguage = acceptLanguage,
        okHttpClient = OkHttpClient.Builder().addInterceptor(this).build(),
    )
}
