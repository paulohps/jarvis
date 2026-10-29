package app.jarvis.android.network

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.SerializationException
import kotlinx.serialization.json.Json
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Response
import retrofit2.HttpException
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory
import java.io.IOException
import java.util.concurrent.TimeUnit

/** What the client tells the app shell outside the answer of a call. */
sealed interface SessionEvent {
    /** A call answered 401: the token was cleared and the app goes back to the login. */
    data object SessionExpired : SessionEvent

    /** A call answered 403: the user's modules may have changed, so `/me` is read again. */
    data object AccessDenied : SessionEvent
}

/**
 * The client of the API v1 (`docs/api/v1.yaml`), over Retrofit and OkHttp.
 *
 * Every call carries the Sanctum token of the device as `Bearer`, and the app's
 * language as `Accept-Language` (the server answers its messages in it before
 * the sign-in; afterwards it uses the user's saved locale). Any 401 of an
 * authenticated call clears the token and emits [SessionEvent.SessionExpired].
 *
 * @param baseUrl the server, e.g. `https://jarvis.example.com` (the client appends `/api/v1/`).
 */
class ApiClient(
    baseUrl: String,
    val tokenStore: TokenStore,
    private val acceptLanguage: () -> String? = { null },
    okHttpClient: OkHttpClient = OkHttpClient(),
) {
    private val events = MutableSharedFlow<SessionEvent>(extraBufferCapacity = 8)

    /** The 401 / 403 of any call, for the app shell. */
    val sessionEvents: SharedFlow<SessionEvent> = events.asSharedFlow()

    /** The server root, e.g. `https://jarvis.example.com/`. */
    val serverUrl: HttpUrl = baseUrl.trimEnd('/').plus("/").toHttpUrl()

    /** The headers and the session watch run before any interceptor of [okHttpClient] (a test stub). */
    val httpClient: OkHttpClient = okHttpClient.newBuilder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .apply {
            interceptors().add(0, Interceptor(::authenticate))
            interceptors().add(1, Interceptor(::watchSession))
        }
        .build()

    val api: JarvisApi = Retrofit.Builder()
        .baseUrl(serverUrl.resolve("api/v1/")!!)
        .client(httpClient)
        .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
        .build()
        .create(JarvisApi::class.java)

    /**
     * Calls the API and turns any failure into an [ApiException].
     *
     * ```
     * val user = client.call { me() }.data
     * ```
     */
    suspend fun <T> call(request: suspend JarvisApi.() -> T): T {
        try {
            return api.request()
        } catch (exception: CancellationException) {
            throw exception
        } catch (exception: HttpException) {
            throw exception.toApiException()
        } catch (exception: SerializationException) {
            throw ApiException.Decoding(exception)
        } catch (exception: IllegalArgumentException) {
            throw ApiException.Decoding(exception)
        } catch (exception: IOException) {
            throw ApiException.Transport(exception)
        }
    }

    private fun authenticate(chain: Interceptor.Chain): Response {
        val original = chain.request()
        val builder = original.newBuilder()
            .removeHeader(NO_AUTH_HEADER_NAME)
            .header("Accept", "application/json")

        acceptLanguage()?.let { builder.header("Accept-Language", it) }

        if (original.header(NO_AUTH_HEADER_NAME) == null) {
            tokenStore.token()?.let { builder.header("Authorization", "Bearer $it") }
        }

        return chain.proceed(builder.build())
    }

    private fun watchSession(chain: Interceptor.Chain): Response {
        val request = chain.request()
        val response = chain.proceed(request)

        when (response.code) {
            // A 401 on the sign-in itself (no token sent) is not a lost session.
            401 -> if (request.header("Authorization") != null) {
                runBlocking { tokenStore.clear() }
                events.tryEmit(SessionEvent.SessionExpired)
            }

            403 -> events.tryEmit(SessionEvent.AccessDenied)
        }

        return response
    }

    private fun HttpException.toApiException(): ApiException {
        val body = runCatching {
            response()?.errorBody()?.string()?.takeIf { it.isNotBlank() }?.let { json.decodeFromString<ErrorBody>(it) }
        }.getOrNull()
        val message = body?.message.orEmpty()

        return when (code()) {
            401 -> ApiException.Unauthenticated()
            403 -> ApiException.Forbidden(message)
            404 -> ApiException.NotFound(message)
            422 -> ApiException.Validation(message, body?.errors.orEmpty())
            429 -> ApiException.TooManyRequests(message)
            else -> ApiException.Server(code(), message)
        }
    }

    companion object {
        private const val NO_AUTH_HEADER_NAME = "X-Jarvis-No-Auth"

        /** The marker of the sign-in endpoints: sent without the token. */
        const val NO_AUTH_HEADER = "$NO_AUTH_HEADER_NAME: 1"

        /** The JSON of the API: unknown keys are ignored, `null` fields are sent. */
        val json = Json {
            ignoreUnknownKeys = true
            explicitNulls = true
            encodeDefaults = true
            coerceInputValues = true
        }
    }
}
