package app.jarvis.android

import android.Manifest
import android.content.ActivityNotFoundException
import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.ui.Modifier
import androidx.core.net.toUri
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import app.jarvis.android.push.NotificationPresenter
import app.jarvis.android.ui.JarvisApp
import app.jarvis.android.ui.schedule.WallClock
import app.jarvis.android.ui.theme.JarvisTheme
import app.jarvis.android.voice.AppActionCapability
import app.jarvis.android.voice.ShortcutMapping
import java.time.LocalDateTime
import kotlinx.coroutines.launch

/**
 * The only activity. It opens the deep links it is launched with (a push
 * tapped, FCM's own or ours, carries `deep_link`; a `jarvis://` link) and the
 * web-only pages in the browser, and runs the App Actions (`res/xml/shortcuts.xml`)
 * in the voice sheet.
 */
class MainActivity : ComponentActivity() {
    private val container
        get() = (application as JarvisApplication).container

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        if (savedInstanceState == null) {
            openDeepLink(intent)
            runAppAction(intent)
        }

        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                container.model.webLinks.collect { url ->
                    try {
                        startActivity(Intent(Intent.ACTION_VIEW, url.toUri()))
                    } catch (_: ActivityNotFoundException) {
                        // No browser on the phone: nothing to open it with.
                    }
                }
            }
        }

        setContent {
            JarvisTheme {
                // The surface sets the content color, so text reads in the dark theme too.
                Surface(Modifier.fillMaxSize()) {
                    JarvisApp(container)
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        openDeepLink(intent)
        runAppAction(intent)
    }

    /** An App Action of the Assistant: its voice action, or the microphone when a value is missing. */
    private fun runAppAction(intent: Intent?) {
        val capability = AppActionCapability.forIntentAction(intent?.action) ?: return
        val extras = capability.keys.associateWith { intent?.getStringExtra(it) }
        val now = LocalDateTime.now(WallClock.zone(container.model.user?.timezone))
        val action = ShortcutMapping.voiceAction(capability, extras, now)

        if (action != null) {
            container.voice.run(action)
        } else {
            container.voice.begin(shouldShowRequestPermissionRationale(Manifest.permission.RECORD_AUDIO))
        }
    }

    private fun openDeepLink(intent: Intent?) {
        if (AppActionCapability.forIntentAction(intent?.action) != null) {
            return
        }

        val link = intent?.getStringExtra(NotificationPresenter.EXTRA_DEEP_LINK) ?: intent?.dataString

        if (link != null) {
            container.model.open(link)
        }
    }
}
