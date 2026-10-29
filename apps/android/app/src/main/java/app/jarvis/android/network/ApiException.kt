package app.jarvis.android.network

/** A failed call to the API v1, by what the app does about it. */
sealed class ApiException(message: String?) : Exception(message) {
    /** 401: no token or a revoked one. The session is over (back to the login). */
    class Unauthenticated : ApiException("Unauthenticated")

    /** 403: the module is switched off for the user, or the record is someone else's. */
    class Forbidden(val serverMessage: String) : ApiException(serverMessage)

    /** 404. */
    class NotFound(val serverMessage: String) : ApiException(serverMessage)

    /** 422, in the Laravel format; the messages come in the user's language. */
    class Validation(val serverMessage: String, val errors: Map<String, List<String>>) : ApiException(serverMessage)

    /** 429. */
    class TooManyRequests(val serverMessage: String) : ApiException(serverMessage)

    /** Any other status. */
    class Server(val status: Int, val serverMessage: String) : ApiException(serverMessage)

    /** No answer (no network, timeout). */
    class Transport(cause: Throwable) : ApiException(cause.message) {
        init {
            initCause(cause)
        }
    }

    /** An answer the app could not read. */
    class Decoding(cause: Throwable) : ApiException(cause.message) {
        init {
            initCause(cause)
        }
    }

    /** The message to show: the first validation error, otherwise the server's message. */
    val displayMessage: String?
        get() = when (this) {
            is Validation -> errors.values.firstNotNullOfOrNull { it.firstOrNull() } ?: serverMessage.ifEmpty { null }
            is Forbidden -> serverMessage.ifEmpty { null }
            is NotFound -> serverMessage.ifEmpty { null }
            is TooManyRequests -> serverMessage.ifEmpty { null }
            is Server -> serverMessage.ifEmpty { null }
            is Unauthenticated, is Transport, is Decoding -> null
        }

    /** The first message of one validation field. */
    fun firstError(field: String): String? = (this as? Validation)?.errors?.get(field)?.firstOrNull()
}
