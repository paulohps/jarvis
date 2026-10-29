package app.jarvis.android.voice

import android.content.Context
import androidx.core.content.edit
import app.jarvis.android.i18n.AppLanguage
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.VoiceAction
import app.jarvis.android.network.VoiceCommandResult
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import java.time.LocalDateTime
import java.time.ZoneId

/** Where the user wants a spoken command to be interpreted (US-10.2). */
enum class InterpreterPreference(val storageValue: String) {
    /** On this phone, with Gemini Nano: the text never leaves the device. */
    Local("local"),

    /** On the server, with the household's AI provider (`POST /voice/commands`). */
    Remote("remote"),

    /** Local when this phone can, otherwise remote. */
    Automatic("automatic"),
    ;

    companion object {
        /** A saved value; automatic until the user picks one. */
        fun fromStorage(value: String?): InterpreterPreference = entries.firstOrNull { it.storageValue == value } ?: Automatic
    }
}

/** The saved preference of this device. */
interface InterpreterPreferenceStore {
    val preference: StateFlow<InterpreterPreference>

    fun save(preference: InterpreterPreference)
}

/** [InterpreterPreferenceStore] in the app's `SharedPreferences`. */
class SharedPreferencesInterpreterStore(context: Context) : InterpreterPreferenceStore {
    private val preferences = context.applicationContext.getSharedPreferences("voice", Context.MODE_PRIVATE)
    private val mutablePreference = MutableStateFlow(InterpreterPreference.fromStorage(preferences.getString(KEY, null)))
    override val preference: StateFlow<InterpreterPreference> = mutablePreference.asStateFlow()

    override fun save(preference: InterpreterPreference) {
        preferences.edit { putString(KEY, preference.storageValue) }
        mutablePreference.value = preference
    }

    private companion object {
        const val KEY = "interpreter"
    }
}

/** The interpreter a command goes to. */
enum class InterpreterChoice {
    Local,
    Remote,

    /** The user asked for the local one and this phone cannot run it; the text is not sent to the server behind their back. */
    LocalUnavailable,
}

/**
 * Picks the interpreter from the preference and whether the on-device
 * model can run now (Gemini Nano ready on the phone, the language supported).
 */
object InterpreterSelection {
    fun choose(preference: InterpreterPreference, isLocalAvailable: Boolean): InterpreterChoice = when (preference) {
        InterpreterPreference.Local -> if (isLocalAvailable) InterpreterChoice.Local else InterpreterChoice.LocalUnavailable
        InterpreterPreference.Remote -> InterpreterChoice.Remote
        InterpreterPreference.Automatic -> if (isLocalAvailable) InterpreterChoice.Local else InterpreterChoice.Remote
    }
}

/** Turns a spoken text into the structured action, on the device. */
interface OnDeviceVoiceModel {
    /** Whether the model can run now, for the language of the command. */
    suspend fun isAvailable(language: AppLanguage): Boolean

    /**
     * The action the text asks for; `null` when no action fits.
     *
     * @param now the user's wall clock, so "tomorrow at 9" becomes a date.
     */
    suspend fun action(text: String, language: AppLanguage, now: LocalDateTime, zone: ZoneId): VoiceAction?
}

/** Why a command could not be interpreted. */
sealed class VoiceInterpreterException(message: String?) : Exception(message) {
    /** The user wants the local interpreter and this phone cannot run it. */
    class LocalUnavailable : VoiceInterpreterException("The on-device interpreter is not available")

    /** The on-device model found no action in the text. */
    class NotUnderstood : VoiceInterpreterException("The command was not understood")

    /** The on-device model failed. */
    class LocalFailed(cause: Throwable) : VoiceInterpreterException(cause.message) {
        init {
            initCause(cause)
        }
    }

    /** The call to the server failed. */
    class Api(val exception: ApiException) : VoiceInterpreterException(exception.message) {
        init {
            initCause(exception)
        }
    }
}

/**
 * Interprets a spoken text with the interpreter the preference picks and
 * runs it: the local model structures the action and `POST /voice/actions`
 * runs it, the remote one is `POST /voice/commands`. Both end in the same
 * action on the server. In automatic mode a command the phone cannot
 * structure gets a second try on the server (as on the iPhone).
 */
class VoiceInterpreter(private val service: VoiceService, private val model: OnDeviceVoiceModel) {
    suspend fun choice(preference: InterpreterPreference, language: AppLanguage): InterpreterChoice =
        InterpreterSelection.choose(preference, isLocalAvailable(language))

    suspend fun isLocalAvailable(language: AppLanguage): Boolean = try {
        model.isAvailable(language)
    } catch (exception: CancellationException) {
        throw exception
    } catch (_: Exception) {
        false
    }

    /** @throws VoiceInterpreterException */
    suspend fun interpret(
        text: String,
        preference: InterpreterPreference,
        language: AppLanguage,
        now: LocalDateTime,
        zone: ZoneId,
    ): VoiceCommandResult = when (choice(preference, language)) {
        InterpreterChoice.LocalUnavailable -> throw VoiceInterpreterException.LocalUnavailable()
        InterpreterChoice.Remote -> remote(text)
        InterpreterChoice.Local -> {
            val action = try {
                model.action(text, language, now, zone)
            } catch (exception: CancellationException) {
                throw exception
            } catch (exception: Exception) {
                if (preference != InterpreterPreference.Automatic) {
                    throw VoiceInterpreterException.LocalFailed(exception)
                }

                null
            }

            when {
                action != null -> api { service.run(action) }
                preference == InterpreterPreference.Automatic -> remote(text)
                else -> throw VoiceInterpreterException.NotUnderstood()
            }
        }
    }

    private suspend fun remote(text: String): VoiceCommandResult = api { service.interpret(text) }

    private suspend fun api(call: suspend () -> VoiceCommandResult): VoiceCommandResult = try {
        call()
    } catch (exception: ApiException) {
        throw VoiceInterpreterException.Api(exception)
    }
}
