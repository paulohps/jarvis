package app.jarvis.android.ui.whatsapp

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.util.Base64
import android.util.LruCache
import android.widget.Toast
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Groups
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import app.jarvis.android.R
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

/**
 * The pictures and files of the WhatsApp chats. They come from WhatsApp's
 * own servers (the profile pictures) or from Jarvis' signed media URLs, so
 * they are read without the API token, by a plain client, and the decoded
 * pictures are kept in memory while the app runs.
 */
internal object WhatsappImages {
    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .build()

    private val cache = object : LruCache<String, Bitmap>(32 * 1024 * 1024) {
        override fun sizeOf(key: String, value: Bitmap): Int = value.allocationByteCount
    }

    /** The picture at [url], scaled down to about [maxSide] pixels; null when it cannot be read (an expired URL). */
    suspend fun load(url: String, maxSide: Int): Bitmap? {
        val key = "$url@$maxSide"
        cache.get(key)?.let { return it }

        return withContext(Dispatchers.IO) {
            runCatching {
                client.newCall(Request.Builder().url(url).build()).execute().use { response ->
                    if (!response.isSuccessful) {
                        return@use null
                    }

                    decode(response.body.bytes(), maxSide)
                }
            }.getOrNull()?.also { cache.put(key, it) }
        }
    }

    /** A base64 JPEG (the tiny preview WhatsApp sends). */
    fun decodeBase64(base64: String): Bitmap? = runCatching {
        decode(Base64.decode(base64, Base64.DEFAULT), Int.MAX_VALUE)
    }.getOrNull()

    private fun decode(bytes: ByteArray, maxSide: Int): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)

        var sample = 1

        while (bounds.outWidth / (sample * 2) >= maxSide || bounds.outHeight / (sample * 2) >= maxSide) {
            sample *= 2
        }

        return BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
    }
}

/** `#rrggbb` of the API as a color, or [fallback]. */
internal fun hexColor(hex: String?, fallback: Color): Color =
    hex?.let { runCatching { Color(android.graphics.Color.parseColor(it)) }.getOrNull() } ?: fallback

/** A picture read from [url], [onFailure] when it cannot be read. */
@Composable
internal fun RemoteImage(
    url: String,
    contentDescription: String?,
    modifier: Modifier = Modifier,
    maxSide: Int = 1280,
    contentScale: ContentScale = ContentScale.Crop,
    onFailure: () -> Unit = {},
    placeholder: @Composable () -> Unit = {},
) {
    val bitmap by produceState<Bitmap?>(null, url) {
        value = WhatsappImages.load(url, maxSide)

        if (value == null) {
            onFailure()
        }
    }

    val current = bitmap

    if (current != null) {
        Image(current.asImageBitmap(), contentDescription, modifier, contentScale = contentScale)
    } else {
        placeholder()
    }
}

/** The WhatsApp picture of a chat, or its initial (a group icon for a group without one). */
@Composable
internal fun ChatAvatar(name: String, pictureUrl: String?, isGroup: Boolean, size: Dp = 48.dp) {
    var failed by remember(pictureUrl) { mutableStateOf(false) }

    Box(
        Modifier.size(size).clip(CircleShape).background(MaterialTheme.colorScheme.secondaryContainer),
        contentAlignment = Alignment.Center,
    ) {
        val initial: @Composable () -> Unit = {
            if (isGroup) {
                Icon(Icons.Filled.Groups, contentDescription = null, tint = MaterialTheme.colorScheme.onSecondaryContainer)
            } else {
                Text(
                    name.trim().firstOrNull { it.isLetterOrDigit() }?.uppercase() ?: "?",
                    style = MaterialTheme.typography.titleMedium,
                    color = MaterialTheme.colorScheme.onSecondaryContainer,
                )
            }
        }

        if (pictureUrl != null && !failed) {
            RemoteImage(pictureUrl, contentDescription = null, modifier = Modifier.size(size), maxSide = 256, onFailure = { failed = true }, placeholder = initial)
        } else {
            initial()
        }
    }
}

/**
 * A voice note or audio: play and pause, the waveform filling as it plays,
 * and the time. The file is read from [url] only when played; [onFailure]
 * when it cannot be (an expired URL: the screen reads the chat again).
 */
@Composable
internal fun AudioPlayer(url: String?, seconds: Int?, waveform: List<Int>?, tint: Color, onFailure: () -> Unit) {
    var player by remember { mutableStateOf<MediaPlayer?>(null) }
    var isPlaying by remember { mutableStateOf(false) }
    var isPreparing by remember { mutableStateOf(false) }
    var progress by remember { mutableFloatStateOf(0f) }
    var elapsed by remember { mutableIntStateOf(0) }
    var duration by remember { mutableIntStateOf((seconds ?: 0) * 1000) }

    DisposableEffect(url) {
        onDispose {
            player?.release()
            player = null
        }
    }

    LaunchedEffect(isPlaying) {
        while (isPlaying) {
            player?.let {
                elapsed = it.currentPosition
                progress = if (duration > 0) it.currentPosition.toFloat() / duration else 0f
            }
            delay(200)
        }
    }

    fun toggle() {
        val current = player

        when {
            url == null -> return
            current != null && isPlaying -> {
                current.pause()
                isPlaying = false
            }

            current != null -> {
                current.start()
                isPlaying = true
            }

            else -> {
                isPreparing = true
                player = MediaPlayer().apply {
                    setAudioAttributes(AudioAttributes.Builder().setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).setUsage(AudioAttributes.USAGE_MEDIA).build())
                    setOnPreparedListener {
                        isPreparing = false
                        duration = it.duration.takeIf { length -> length > 0 } ?: duration
                        it.start()
                        isPlaying = true
                    }
                    setOnCompletionListener {
                        isPlaying = false
                        progress = 0f
                        elapsed = 0
                        it.seekTo(0)
                    }
                    setOnErrorListener { mediaPlayer, _, _ ->
                        isPreparing = false
                        isPlaying = false
                        mediaPlayer.release()
                        player = null
                        onFailure()
                        true
                    }
                    runCatching {
                        setDataSource(url)
                        prepareAsync()
                    }.onFailure {
                        isPreparing = false
                        onFailure()
                    }
                }
            }
        }
    }

    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        IconButton(onClick = ::toggle, enabled = url != null) {
            when {
                isPreparing -> CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp, color = tint)
                isPlaying -> Icon(Icons.Filled.Pause, contentDescription = stringResource(R.string.whatsapp_pause), tint = tint)
                else -> Icon(Icons.Filled.PlayArrow, contentDescription = stringResource(R.string.whatsapp_play), tint = tint)
            }
        }

        val bars = waveform?.takeIf { it.isNotEmpty() } ?: List(40) { 40 }

        Row(Modifier.height(28.dp).width(150.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(1.dp)) {
            bars.forEachIndexed { index, height ->
                val played = index.toFloat() / bars.size < progress

                Box(
                    Modifier
                        .weight(1f)
                        .fillMaxHeight((height.coerceIn(8, 100)) / 100f)
                        .background(if (played) tint else tint.copy(alpha = 0.35f), RoundedCornerShape(1.dp)),
                )
            }
        }

        Text(clock(if (isPlaying || elapsed > 0) elapsed else duration), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

/** `m:ss`. */
internal fun clock(milliseconds: Int): String {
    val total = (milliseconds / 1000).coerceAtLeast(0)

    return "%d:%02d".format(total / 60, total % 60)
}

/** Opens a signed media URL in the app of the phone that shows [mime] (a video, a document). */
internal fun openMedia(context: Context, url: String, mime: String?) {
    val intent = Intent(Intent.ACTION_VIEW).apply {
        if (mime != null) setDataAndType(url.toUri(), mime) else data = url.toUri()
        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    }

    try {
        context.startActivity(intent)
    } catch (_: ActivityNotFoundException) {
        try {
            // No app for the type: the browser downloads it.
            context.startActivity(Intent(Intent.ACTION_VIEW, url.toUri()).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        } catch (_: ActivityNotFoundException) {
            Toast.makeText(context, R.string.whatsapp_open_failed, Toast.LENGTH_SHORT).show()
        }
    }
}
