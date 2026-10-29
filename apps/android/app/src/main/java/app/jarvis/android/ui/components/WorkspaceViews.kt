package app.jarvis.android.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.app.SessionState
import app.jarvis.android.network.User
import app.jarvis.android.network.UserWorkspace
import app.jarvis.android.network.WorkspaceColorOption
import app.jarvis.android.network.WorkspaceSummary
import app.jarvis.android.ui.whatsapp.ColorSwatch
import app.jarvis.android.ui.whatsapp.hexColor

/** The signed-in user, following `/me` as it is read again (a workspace preference saved, a membership changed). */
@Composable
fun AppModel.observedUser(): User? {
    val session by state.collectAsStateWithLifecycle()

    return (session as? SessionState.SignedIn)?.user
}

/**
 * The dot of a workspace's color, next to a shared record (a list, a
 * reminder) when the user is in two or more workspaces with its module.
 * [contentDescription] is what TalkBack reads (the workspace's name); hidden without it.
 */
@Composable
fun WorkspaceDot(hex: String, modifier: Modifier = Modifier, size: Dp = 10.dp, contentDescription: String? = null) {
    Box(
        modifier
            .size(size)
            .background(hexColor(hex, MaterialTheme.colorScheme.outline), CircleShape)
            .let { dot -> if (contentDescription != null) dot.semantics { this.contentDescription = contentDescription } else dot },
    )
}

/** The dot of a shared record's workspace, read by TalkBack as "Workspace: name". */
@Composable
fun WorkspaceDot(workspace: WorkspaceSummary, modifier: Modifier = Modifier, size: Dp = 10.dp) {
    WorkspaceDot(workspace.colorHex, modifier, size, stringResource(R.string.workspace_named, workspace.name))
}

/** A workspace's dot and name, as a small badge. */
@Composable
fun WorkspaceBadge(workspace: WorkspaceSummary, modifier: Modifier = Modifier) {
    Row(modifier, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        WorkspaceDot(workspace.colorHex, size = 8.dp)
        Text(
            workspace.name,
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

/**
 * Where a new list, recurring list or reminder goes, or where an edited one
 * moves to: one row per workspace of the user with the module, with its
 * color. The forms show it only when there are two or more.
 */
@Composable
fun WorkspacePickerSection(workspaces: List<UserWorkspace>, selection: String?, onSelect: (String) -> Unit) {
    Section(header = stringResource(R.string.workspace)) {
        workspaces.forEachIndexed { index, workspace ->
            ListRow(
                title = workspace.name,
                showDivider = index < workspaces.lastIndex,
                onClick = { onSelect(workspace.id) },
                modifier = Modifier.testTag("workspace.picker.${workspace.id}"),
                leading = { WorkspaceDot(workspace.colorHex, size = 14.dp) },
                trailing = {
                    if (workspace.id == selection) {
                        Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    }
                },
            )
        }
    }
}

/**
 * The palette of `GET /workspace-colors`: a circle per color, the picked one
 * checked. With [allowsWorkspaceColor], a first row picks no color of one's
 * own (`null`): the workspace's, shown with [workspaceColorHex].
 */
@Composable
fun WorkspaceColorPalette(
    colors: List<WorkspaceColorOption>,
    selection: String?,
    onSelect: (String?) -> Unit,
    allowsWorkspaceColor: Boolean = false,
    workspaceColorHex: String? = null,
    isEnabled: Boolean = true,
) {
    Column(Modifier.fillMaxWidth()) {
        if (allowsWorkspaceColor) {
            ListRow(
                title = stringResource(R.string.workspace_use_workspace_color),
                showDivider = true,
                onClick = if (isEnabled) ({ onSelect(null) }) else null,
                modifier = Modifier.testTag("workspace.color.workspace"),
                leading = if (workspaceColorHex != null) {
                    { WorkspaceDot(workspaceColorHex, size = 16.dp) }
                } else {
                    null
                },
                trailing = {
                    if (selection == null) {
                        Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    }
                },
            )
        }

        FlowRow(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            colors.forEach { color ->
                val name = workspaceColorName(color.name)

                ColorSwatch(
                    color.hex,
                    isPicked = selection == color.name,
                    onClick = { if (isEnabled) onSelect(color.name) },
                    modifier = Modifier.testTag("workspace.color.${color.name}").semantics { contentDescription = name },
                )
            }
        }
    }
}

/** A color of the palette by its name, in the app's language (the name itself for one the app does not know). */
@Composable
fun workspaceColorName(name: String): String {
    val resource = when (name) {
        "red" -> R.string.color_red
        "orange" -> R.string.color_orange
        "yellow" -> R.string.color_yellow
        "green" -> R.string.color_green
        "mint" -> R.string.color_mint
        "teal" -> R.string.color_teal
        "cyan" -> R.string.color_cyan
        "blue" -> R.string.color_blue
        "indigo" -> R.string.color_indigo
        "purple" -> R.string.color_purple
        "pink" -> R.string.color_pink
        "brown" -> R.string.color_brown
        "gray" -> R.string.color_gray
        else -> null
    }

    return resource?.let { stringResource(it) } ?: name.replaceFirstChar { it.uppercase() }
}
