package app.jarvis.android.ui.voice

import android.Manifest
import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.content.Intent
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Mic
import androidx.compose.material.icons.filled.MicOff
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledIconButton
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.jarvis.android.R
import app.jarvis.android.network.WhatsappTargetType
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.voice.VoiceController
import app.jarvis.android.voice.VoicePhase
import app.jarvis.android.voice.VoiceResponseFlow

/** The activity of a Compose context, for `shouldShowRequestPermissionRationale`. */
private tailrec fun Context.findActivity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.findActivity()
    else -> null
}

/** Whether Android still shows the `RECORD_AUDIO` prompt after a refusal. */
private fun Context.shouldShowMicrophoneRationale(): Boolean =
    findActivity()?.shouldShowRequestPermissionRationale(Manifest.permission.RECORD_AUDIO) ?: false

/** The global microphone button (US-10.1): the floating action button of every tab. */
@Composable
fun VoiceButton(voice: VoiceController, modifier: Modifier = Modifier) {
    val context = LocalContext.current

    FloatingActionButton(
        onClick = { voice.begin(context.shouldShowMicrophoneRationale()) },
        modifier = modifier.testTag("voice.microphone"),
    ) {
        Icon(Icons.Filled.Mic, contentDescription = stringResource(R.string.voice_ask_jarvis))
    }
}

/**
 * What the microphone heard and what came of it (US-10.1): the transcript
 * while the user speaks, then the answer. Without the microphone it explains
 * how to allow it, and never crashes.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun VoiceSheet(voice: VoiceController) {
    val isPresented by voice.isPresented.collectAsStateWithLifecycle()
    val phase by voice.phase.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        voice.didAnswerPermission(granted, context.shouldShowMicrophoneRationale())
    }

    LaunchedEffect(phase) {
        if (phase == VoicePhase.RequestingPermission) {
            voice.didShowPermissionPrompt()
            launcher.launch(Manifest.permission.RECORD_AUDIO)
        }
    }

    if (!isPresented) {
        return
    }

    ModalBottomSheet(
        onDismissRequest = voice::dismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
    ) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 24.dp).padding(bottom = 24.dp).navigationBarsPadding(),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            Header(onClose = voice::dismiss)
            Content(voice, phase)
        }
    }
}

@Composable
private fun Header(onClose: () -> Unit) {
    androidx.compose.foundation.layout.Box(Modifier.fillMaxWidth()) {
        Text(stringResource(R.string.voice_ask_jarvis), style = MaterialTheme.typography.titleMedium, modifier = Modifier.align(Alignment.Center))
        IconButton(onClick = onClose, modifier = Modifier.align(Alignment.CenterEnd).testTag("voice.close")) {
            Icon(Icons.Filled.Close, contentDescription = stringResource(R.string.voice_close))
        }
    }
}

@Composable
private fun Content(voice: VoiceController, phase: VoicePhase) {
    val context = LocalContext.current

    when (phase) {
        VoicePhase.Idle, VoicePhase.RequestingPermission -> CircularProgressIndicator()
        VoicePhase.Listening -> Listening(voice)
        is VoicePhase.AccessDenied -> AccessDenied(
            canAskAgain = phase.canAskAgain,
            onAllow = voice::askPermission,
            onOpenSettings = {
                context.startActivity(
                    Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, "package:${context.packageName}".toUri()),
                )
            },
        )

        is VoicePhase.Failed -> {
            Outcome(phase.message, Icons.Outlined.ErrorOutline, MaterialTheme.colorScheme.error)
            ListenAgainButton(voice)
        }

        is VoicePhase.Interpreting -> {
            phase.text?.let { Heard(it) }
            CircularProgressIndicator()
            Text(stringResource(R.string.voice_thinking), color = MaterialTheme.colorScheme.onSurfaceVariant)
        }

        is VoicePhase.Answered -> {
            phase.text?.let { Heard(it) }
            Answer(voice, phase.flow)
        }
    }
}

@Composable
private fun Answer(voice: VoiceController, flow: VoiceResponseFlow) {
    val isSending by voice.isSending.collectAsStateWithLifecycle()

    when (flow) {
        is VoiceResponseFlow.Done -> {
            Outcome(flow.message, Icons.Filled.CheckCircle, MaterialTheme.colorScheme.primary)
            ListenAgainButton(voice)
        }

        is VoiceResponseFlow.Refused -> {
            Outcome(flow.message, Icons.Filled.Lock, MaterialTheme.colorScheme.onSurfaceVariant)
            ListenAgainButton(voice)
        }

        is VoiceResponseFlow.Failed -> {
            Outcome(flow.message, Icons.Outlined.ErrorOutline, MaterialTheme.colorScheme.error)
            ListenAgainButton(voice)
        }

        // `needs_clarification`: the candidates; nothing runs until one is picked.
        is VoiceResponseFlow.Clarify -> {
            Message(flow.message)
            flow.clarification.options.forEach { option ->
                OutlinedButton(
                    onClick = { voice.choose(option) },
                    enabled = !isSending,
                    modifier = Modifier.fillMaxWidth().testTag("voice.option.${option.id}"),
                ) {
                    Text(option.label)
                }
            }
            CancelButton(voice, isSending)
        }

        // `needs_confirmation`: what would be sent; only the tap on Send sends it.
        is VoiceResponseFlow.Confirm -> {
            val summary = flow.confirmation.summary

            Message(flow.message)
            Section {
                ListRow(
                    title = stringResource(if (summary.targetType == WhatsappTargetType.Group) R.string.voice_group else R.string.voice_contact),
                    trailing = { Text(summary.target, color = MaterialTheme.colorScheme.onSurfaceVariant) },
                    showDivider = true,
                )
                ListRow(
                    title = stringResource(R.string.voice_account),
                    trailing = { Text(summary.account, color = MaterialTheme.colorScheme.onSurfaceVariant) },
                    showDivider = true,
                )
                ListRow(title = summary.body, modifier = Modifier.testTag("voice.confirmation-body"))
            }
            Button(onClick = voice::confirm, enabled = !isSending, modifier = Modifier.fillMaxWidth().testTag("voice.confirm")) {
                Icon(Icons.AutoMirrored.Filled.Send, contentDescription = null)
                Text(stringResource(R.string.send), modifier = Modifier.padding(start = 8.dp))
            }
            CancelButton(voice, isSending)
        }
    }
}

@Composable
private fun Message(message: String) {
    Text(message, style = MaterialTheme.typography.titleLarge, textAlign = TextAlign.Center, modifier = Modifier.testTag("voice.message"))
}

@Composable
private fun CancelButton(voice: VoiceController, isSending: Boolean) {
    TextButton(onClick = voice::cancelAnswer, enabled = !isSending, modifier = Modifier.testTag("voice.cancel")) {
        Text(stringResource(R.string.cancel))
    }
}

@Composable
private fun Listening(voice: VoiceController) {
    val transcript by voice.speech.transcript.collectAsStateWithLifecycle()

    Text(
        transcript.ifEmpty { stringResource(R.string.voice_listening) },
        style = MaterialTheme.typography.titleLarge,
        textAlign = TextAlign.Center,
        color = if (transcript.isEmpty()) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface,
        modifier = Modifier.fillMaxWidth().testTag("voice.transcript"),
    )

    FilledIconButton(onClick = voice::stopListening, modifier = Modifier.size(80.dp).testTag("voice.stop")) {
        Icon(Icons.Filled.Mic, contentDescription = stringResource(R.string.voice_stop), modifier = Modifier.size(36.dp))
    }
}

/** No microphone: why it is needed and how to allow it. */
@Composable
private fun AccessDenied(canAskAgain: Boolean, onAllow: () -> Unit, onOpenSettings: () -> Unit) {
    Icon(Icons.Filled.MicOff, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(48.dp))
    Text(stringResource(R.string.voice_microphone_off), style = MaterialTheme.typography.titleLarge)
    Text(
        stringResource(if (canAskAgain) R.string.voice_microphone_explanation else R.string.voice_microphone_settings_explanation),
        textAlign = TextAlign.Center,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.testTag("voice.permission-explanation"),
    )

    if (canAskAgain) {
        Button(onClick = onAllow, modifier = Modifier.testTag("voice.allow-microphone")) {
            Text(stringResource(R.string.voice_allow_microphone))
        }
    } else {
        Button(onClick = onOpenSettings, modifier = Modifier.testTag("voice.open-settings")) {
            Text(stringResource(R.string.push_open_settings))
        }
    }
}

@Composable
internal fun Heard(text: String) {
    Text(
        "“$text”",
        style = MaterialTheme.typography.bodyLarge,
        textAlign = TextAlign.Center,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.testTag("voice.transcript"),
    )
}

@Composable
internal fun Outcome(message: String, icon: ImageVector, tint: Color) {
    Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(48.dp))
    Text(message, style = MaterialTheme.typography.titleLarge, textAlign = TextAlign.Center, modifier = Modifier.testTag("voice.message"))
}

@Composable
internal fun ListenAgainButton(voice: VoiceController) {
    val context = LocalContext.current

    OutlinedButton(onClick = { voice.listen(context.shouldShowMicrophoneRationale()) }, modifier = Modifier.testTag("voice.listen-again")) {
        Icon(Icons.Filled.Mic, contentDescription = null)
        Text(stringResource(R.string.try_again), modifier = Modifier.padding(start = 8.dp))
    }
}
