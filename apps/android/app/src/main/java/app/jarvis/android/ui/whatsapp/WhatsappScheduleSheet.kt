package app.jarvis.android.ui.whatsapp

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.media.MediaRecorder
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Stop
import androidx.compose.material.icons.outlined.AttachFile
import androidx.compose.material.icons.outlined.Description
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material.icons.outlined.Mic
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.core.content.ContextCompat
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.network.ApiException
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.SectionTextField
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.schedule.DateTimeRows
import app.jarvis.android.ui.schedule.WallClock
import java.io.File
import java.time.ZonedDateTime
import java.time.temporal.ChronoUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** The largest file the server takes for a scheduled message (12 MB). */
internal const val MAX_SCHEDULED_FILE_BYTES = 12L * 1024 * 1024

/** The longest voice note recorded here; the recording stops by itself. */
private const val MAX_VOICE_NOTE_SECONDS = 15 * 60

/** The type a voice note recorded here is uploaded as (AAC in MPEG-4). */
internal const val VOICE_NOTE_MIME = "audio/mp4"

/**
 * A message sent to the chat once, later: a text, or a file with the text as
 * its caption — an image or a video (Photo Picker), any document, or a voice
 * note recorded here (which goes without text) — and when, on the user's
 * wall clock. [onSave] gets the text, the time and the file, if any.
 */
@Composable
internal fun ScheduleMessageSheet(
    model: AppModel,
    initialText: String,
    onSave: suspend (String, String, PickedFile?) -> Unit,
    onDismiss: () -> Unit,
) {
    val context = LocalContext.current
    val zone = WallClock.zone(model.user?.timezone)
    val scope = rememberCoroutineScope()
    var text by remember { mutableStateOf(initialText) }
    var at by remember { mutableStateOf(ZonedDateTime.now(zone).plusHours(1).truncatedTo(ChronoUnit.HOURS).toLocalDateTime()) }
    var file by remember { mutableStateOf<PickedFile?>(null) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    var localFailure by remember { mutableStateOf<String?>(null) }
    var isSaving by remember { mutableStateOf(false) }
    val recorder = remember { VoiceNoteRecorder(context) }
    var isRecording by remember { mutableStateOf(false) }
    var seconds by remember { mutableIntStateOf(0) }
    val tooBig = stringResource(R.string.whatsapp_schedule_file_too_big)
    val noMicrophone = stringResource(R.string.whatsapp_microphone_refused)
    val isVoiceNote = file?.mime?.startsWith("audio/") == true

    DisposableEffect(Unit) { onDispose { recorder.discard() } }

    val read: (android.net.Uri?) -> Unit = { uri ->
        if (uri != null) {
            scope.launch {
                val picked = withContext(Dispatchers.IO) { readPicked(context, uri, MAX_SCHEDULED_FILE_BYTES) }
                localFailure = if (picked == null) tooBig else null
                file = picked ?: file
            }
        }
    }
    val visualPicker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia(), read)
    val documentPicker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument(), read)

    val startRecording = {
        localFailure = null
        isRecording = recorder.start()
        seconds = 0

        if (!isRecording) {
            localFailure = noMicrophone
        }
    }
    val stopRecording = {
        isRecording = false
        file = recorder.stop()?.let { recorded -> PickedFile(recorded.name, VOICE_NOTE_MIME, recorded.readBytes()).also { recorded.delete() } }
    }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) {
            startRecording()
        } else {
            localFailure = noMicrophone
        }
    }

    LaunchedEffect(isRecording) {
        while (isRecording) {
            delay(1_000)
            seconds++

            if (seconds >= MAX_VOICE_NOTE_SECONDS) {
                stopRecording()
            }
        }
    }

    FormSheet(
        title = stringResource(R.string.whatsapp_schedule_message),
        confirmLabel = stringResource(R.string.whatsapp_schedule),
        isConfirmEnabled = !isSaving && !isRecording && (file != null || text.isNotBlank()),
        onConfirm = {
            scope.launch {
                isSaving = true
                try {
                    onSave(if (isVoiceNote) "" else text, WallClock.dateTime(at), file)
                    onDismiss()
                } catch (exception: ApiException) {
                    failure = exception
                } finally {
                    isSaving = false
                }
            }
        },
        onDismiss = onDismiss,
    ) {
        if (!isVoiceNote) {
            item {
                Section(footer = if (file != null) stringResource(R.string.whatsapp_schedule_caption_hint) else null) {
                    SectionTextField(
                        value = text,
                        onValueChange = { text = it },
                        placeholder = stringResource(if (file != null) R.string.whatsapp_caption else R.string.whatsapp_message),
                        singleLine = false,
                        minLines = 3,
                        modifier = Modifier.testTag("whatsapp.schedule.text"),
                    )
                }
            }
        }

        item {
            Section(footer = if (isVoiceNote) stringResource(R.string.whatsapp_voice_note_no_text) else null) {
                val picked = file

                when {
                    picked != null -> ListRow(
                        title = picked.name,
                        subtitle = android.text.format.Formatter.formatShortFileSize(context, picked.bytes.size.toLong()),
                        leading = { Icon(pickedIcon(picked.mime), contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                        trailing = {
                            IconButton(onClick = { file = null }, modifier = Modifier.testTag("whatsapp.schedule.remove-file")) {
                                Icon(Icons.Filled.Close, contentDescription = stringResource(R.string.whatsapp_remove_file))
                            }
                        },
                    )

                    isRecording -> ListRow(
                        title = stringResource(R.string.whatsapp_recording, "%d:%02d".format(seconds / 60, seconds % 60)),
                        leading = { Icon(Icons.Outlined.Mic, contentDescription = null, tint = MaterialTheme.colorScheme.error) },
                        trailing = {
                            IconButton(onClick = { recorder.discard(); isRecording = false }) {
                                Icon(Icons.Filled.Close, contentDescription = stringResource(R.string.whatsapp_discard_recording))
                            }
                            IconButton(onClick = stopRecording, modifier = Modifier.testTag("whatsapp.schedule.stop")) {
                                Icon(Icons.Filled.Stop, contentDescription = stringResource(R.string.whatsapp_stop_recording), tint = MaterialTheme.colorScheme.error)
                            }
                        },
                    )

                    else -> {
                        ListRow(
                            title = stringResource(R.string.whatsapp_attach_image_or_video),
                            leading = { Icon(Icons.Outlined.Image, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                            showDivider = true,
                            onClick = { visualPicker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) },
                        )
                        ListRow(
                            title = stringResource(R.string.whatsapp_attach_document),
                            leading = { Icon(Icons.Outlined.Description, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                            showDivider = true,
                            onClick = { documentPicker.launch(arrayOf("*/*")) },
                        )
                        ListRow(
                            title = stringResource(R.string.whatsapp_record_voice_note),
                            leading = { Icon(Icons.Outlined.Mic, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                            modifier = Modifier.testTag("whatsapp.schedule.record"),
                            onClick = {
                                if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
                                    startRecording()
                                } else {
                                    permission.launch(Manifest.permission.RECORD_AUDIO)
                                }
                            },
                        )
                    }
                }
            }
        }

        item {
            Section {
                DateTimeRows(at, model.language.locale, showDivider = false) { at = it }
            }
        }

        localFailure?.let { item { FormError(it) } }
        failure?.let { item { FormError(it.userMessage()) } }
    }
}

private fun pickedIcon(mime: String?) = when {
    mime?.startsWith("audio/") == true -> Icons.Outlined.Mic
    mime?.startsWith("image/") == true || mime?.startsWith("video/") == true -> Icons.Outlined.Image
    else -> Icons.Outlined.AttachFile
}

/**
 * A voice note recorded with the microphone into the cache: AAC in an
 * MPEG-4 container (`.m4a`), mono, which WhatsApp gets as a voice note (the
 * Evolution API converts it).
 */
internal class VoiceNoteRecorder(private val context: Context) {
    private var recorder: MediaRecorder? = null
    private var output: File? = null

    /** @return whether it started; false when the microphone is busy or refused. */
    fun start(): Boolean {
        discard()

        val file = File(context.cacheDir, "voice-note-${System.currentTimeMillis()}.m4a")

        return try {
            recorder = MediaRecorder(context).apply {
                setAudioSource(MediaRecorder.AudioSource.MIC)
                setOutputFormat(MediaRecorder.OutputFormat.MPEG_4)
                setAudioEncoder(MediaRecorder.AudioEncoder.AAC)
                setAudioChannels(1)
                setAudioSamplingRate(44_100)
                setAudioEncodingBitRate(64_000)
                setOutputFile(file.absolutePath)
                prepare()
                start()
            }
            output = file
            true
        } catch (_: Exception) {
            discard()
            false
        }
    }

    /** The recording, or null when it was too short to keep. */
    fun stop(): File? {
        val file = output
        output = null
        val stopped = runCatching { recorder?.stop() }.isSuccess
        release()

        return if (stopped && file != null && file.length() > 0) file else file?.delete().let { null }
    }

    /** Drops the recording, if any. */
    fun discard() {
        runCatching { recorder?.stop() }
        release()
        output?.delete()
        output = null
    }

    private fun release() {
        recorder?.release()
        recorder = null
    }
}
