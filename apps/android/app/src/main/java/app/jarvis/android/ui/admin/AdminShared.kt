package app.jarvis.android.ui.admin

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.outlined.Group
import androidx.compose.material.icons.outlined.Layers
import androidx.compose.material.icons.outlined.MailOutline
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.network.AdminModule
import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.WorkspaceColorOption
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import kotlinx.coroutines.async
import kotlinx.coroutines.coroutineScope

/**
 * The super admin's entries in Settings: the workspaces (and their members),
 * the users and the invitations. Only shown to the super admin; the server
 * refuses anyone else (403).
 */
@Composable
fun AdminSettingsSection(model: AppModel) {
    val entries = listOf(
        Triple(R.string.workspaces, Icons.Outlined.Layers, Destination.AdminWorkspaces),
        Triple(R.string.admin_users, Icons.Outlined.Group, Destination.AdminUsers),
        Triple(R.string.admin_invitations, Icons.Outlined.MailOutline, Destination.AdminInvitations),
    )

    Section(header = stringResource(R.string.admin_administration)) {
        entries.forEachIndexed { index, (title, icon, destination) ->
            ListRow(
                title = stringResource(title),
                showDivider = index < entries.lastIndex,
                onClick = { model.router.push(AppTab.Settings, destination) },
                modifier = Modifier.testTag("settings.admin.${destination.javaClass.simpleName}"),
                leading = { Icon(icon, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
                trailing = { Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
            )
        }
    }
}

/** What the admin forms pick from: the modules and the workspace colors. */
data class AdminCatalog(
    val modules: List<AdminModule> = emptyList(),
    val colors: List<WorkspaceColorOption> = emptyList(),
) {
    /** The modules that can be given: the active ones. */
    val activeModules: List<AdminModule>
        get() = modules.filter { it.isActive }

    fun moduleName(slug: String): String = modules.firstOrNull { it.slug == slug }?.name ?: slug

    /** The modules by name, joined; `null` for none. */
    fun moduleNames(slugs: List<String>): String? = slugs.takeIf { it.isNotEmpty() }?.joinToString(", ", transform = ::moduleName)

    companion object {
        suspend fun load(client: ApiClient): AdminCatalog = coroutineScope {
            val modules = async { client.call { adminModules() }.data }
            val colors = async { client.call { workspaceColors() }.data }

            AdminCatalog(modules.await(), colors.await())
        }
    }
}

/** A switch per module, for a membership or an invitation. */
@Composable
fun ModuleTogglesSection(modules: List<AdminModule>, selection: Set<String>, onChange: (Set<String>) -> Unit) {
    Section(header = stringResource(R.string.workspace_modules)) {
        if (modules.isEmpty()) {
            Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        }

        modules.forEachIndexed { index, module ->
            val isOn = module.slug in selection
            val toggle: (Boolean) -> Unit = { on -> onChange(if (on) selection + module.slug else selection - module.slug) }

            ListRow(
                title = module.name,
                showDivider = index < modules.lastIndex,
                onClick = { toggle(!isOn) },
                modifier = Modifier.testTag("admin.module.${module.slug}"),
                trailing = { Switch(checked = isOn, onCheckedChange = toggle) },
            )
        }
    }
}
