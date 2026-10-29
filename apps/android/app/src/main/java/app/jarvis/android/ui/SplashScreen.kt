package app.jarvis.android.ui

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.size
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.colorResource
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import kotlinx.coroutines.delay

/**
 * The opening while the session loads: the system splash (`Theme.Jarvis`)
 * drawn again — the brand blue and the mark at the 288 dp of a splash icon
 * without a background — so the hand-over does not jump. A spinner shows up
 * only when `/me` takes longer than a moment.
 */
@Composable
fun SplashScreen() {
    var isSlow by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        delay(1_000)
        isSlow = true
    }

    Box(Modifier.fillMaxSize().background(colorResource(R.color.brand)), contentAlignment = Alignment.Center) {
        Image(painterResource(R.drawable.ic_launcher_foreground), contentDescription = null, modifier = Modifier.size(288.dp))

        AnimatedVisibility(isSlow, enter = fadeIn(), modifier = Modifier.offset(y = 110.dp)) {
            CircularProgressIndicator(color = Color.White, modifier = Modifier.size(28.dp), strokeWidth = 3.dp)
        }
    }
}
