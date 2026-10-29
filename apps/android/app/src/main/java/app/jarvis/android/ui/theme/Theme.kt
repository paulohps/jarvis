package app.jarvis.android.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalInspectionMode

/**
 * The Material 3 look of Jarvis on Android: the colors come from the user's
 * wallpaper (Material You, every supported Android has it), with the Material
 * type scale and shapes. The brand blue is the fallback, for previews.
 */
private val LightColors = lightColorScheme(
    primary = Color(0xFF0A5BE0),
    onPrimary = Color.White,
    primaryContainer = Color(0xFFDAE2FF),
    onPrimaryContainer = Color(0xFF001848),
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFFB2C5FF),
    onPrimary = Color(0xFF002B75),
    primaryContainer = Color(0xFF0040A4),
    onPrimaryContainer = Color(0xFFDAE2FF),
)

@Composable
fun JarvisTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    dynamicColor: Boolean = !LocalInspectionMode.current,
    content: @Composable () -> Unit,
) {
    val context = LocalContext.current
    val colorScheme = when {
        dynamicColor && darkTheme -> dynamicDarkColorScheme(context)
        dynamicColor -> dynamicLightColorScheme(context)
        darkTheme -> DarkColors
        else -> LightColors
    }

    MaterialTheme(colorScheme = colorScheme, content = content)
}
