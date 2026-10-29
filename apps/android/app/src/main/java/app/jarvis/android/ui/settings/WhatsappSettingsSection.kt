package app.jarvis.android.ui.settings

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.whatsapp.PriorityColorsSheet
import app.jarvis.android.ui.whatsapp.WhatsappChatToolsStore
import app.jarvis.android.ui.whatsapp.hexColor

/**
 * The WhatsApp settings of the user, the same for every number and chat:
 * the color of each priority (1 to 5).
 */
@Composable
fun WhatsappSettingsSection(model: AppModel) {
    val tools = remember { WhatsappChatToolsStore(model.client) }
    val state by tools.state.collectAsState()
    var isEditingColors by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        tools.loadPriorityColors()
    }

    Section(header = stringResource(R.string.tab_whatsapp), footer = stringResource(R.string.whatsapp_priority_colors_footer)) {
        ListRow(
            title = stringResource(R.string.whatsapp_priority_colors),
            onClick = { isEditingColors = true },
            modifier = Modifier.testTag("settings.whatsapp.priority-colors"),
            trailing = {
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    WhatsappChatToolsStore.PRIORITIES.forEach { priority ->
                        Box(Modifier.size(12.dp).background(hexColor(state.priorityColors.hex(priority), MaterialTheme.colorScheme.outline), CircleShape))
                    }
                }
            },
        )
    }

    if (isEditingColors) {
        PriorityColorsSheet(tools, onSaved = {}, onDismiss = { isEditingColors = false })
    }
}
