package app.jarvis.android.ui.settings

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.produceState
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.jarvis.android.R
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.voice.InterpreterPreference
import app.jarvis.android.voice.VoiceController

/**
 * Where the voice commands are interpreted (US-10.2): on this phone (Gemini
 * Nano), on the server, or automatically (on this phone when it can).
 */
@Composable
fun VoiceSettingsSection(voice: VoiceController, userLocale: String) {
    val preference by voice.preference.collectAsStateWithLifecycle()
    val isLocalAvailable by produceState<Boolean?>(null, userLocale) { value = voice.isLocalAvailable() }

    Section(
        header = stringResource(R.string.settings_voice),
        footer = stringResource(
            when {
                isLocalAvailable == false -> R.string.voice_footer_unavailable
                preference == InterpreterPreference.Local -> R.string.voice_footer_local
                preference == InterpreterPreference.Remote -> R.string.voice_footer_remote
                else -> R.string.voice_footer_automatic
            },
        ),
    ) {
        InterpreterPreference.entries.forEachIndexed { index, option ->
            ListRow(
                title = stringResource(option.title),
                showDivider = index < InterpreterPreference.entries.lastIndex,
                onClick = { voice.updatePreference(option) },
                modifier = Modifier.testTag("settings.voice-interpreter.${option.storageValue}"),
                trailing = {
                    if (option == preference) {
                        Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    }
                },
            )
        }
    }
}

private val InterpreterPreference.title: Int
    get() = when (this) {
        InterpreterPreference.Local -> R.string.voice_interpreter_local
        InterpreterPreference.Remote -> R.string.voice_interpreter_remote
        InterpreterPreference.Automatic -> R.string.voice_interpreter_automatic
    }
