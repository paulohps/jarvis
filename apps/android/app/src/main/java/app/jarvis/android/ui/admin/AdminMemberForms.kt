package app.jarvis.android.ui.admin

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.PersonOff
import androidx.compose.material.icons.filled.PersonRemove
import androidx.compose.material.icons.filled.Star
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.network.AdminMemberInput
import app.jarvis.android.network.AdminUser
import app.jarvis.android.network.AdminWorkspaceDetail
import app.jarvis.android.network.AdminWorkspaceMember
import app.jarvis.android.network.ApiException
import app.jarvis.android.ui.components.ConfirmDialog
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.WorkspaceColorPalette
import app.jarvis.android.ui.components.userMessage
import kotlinx.coroutines.launch

/**
 * A member of a workspace: their modules there, their own color for it (or
 * the workspace's) and making it their default; removing them. The server
 * checks it; a refusal shows under the form.
 */
@Composable
fun AdminMemberForm(
    model: AppModel,
    workspace: AdminWorkspaceDetail,
    member: AdminWorkspaceMember,
    catalog: AdminCatalog,
    onDismiss: () -> Unit,
    onSaved: () -> Unit,
) {
    var modules by remember(member.id) { mutableStateOf(member.modules.toSet()) }
    var color by remember(member.id) { mutableStateOf(member.color) }
    var makesDefault by remember(member.id) { mutableStateOf(false) }
    var isSaving by remember { mutableStateOf(false) }
    var isConfirmingRemove by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()

    FormSheet(
        title = member.user.name,
        confirmLabel = stringResource(R.string.save),
        isConfirmEnabled = !isSaving,
        onConfirm = {
            val input = AdminMemberInput(modules = modules.sorted(), color = color, isDefault = makesDefault)
            isSaving = true
            scope.launch {
                try {
                    model.client.call { updateAdminWorkspaceMember(workspace.id, member.id, input) }
                    onSaved()
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
        item {
            Section {
                ListRow(
                    title = stringResource(R.string.name),
                    showDivider = true,
                    trailing = { Text(member.user.name, color = MaterialTheme.colorScheme.onSurfaceVariant) },
                )
                ListRow(
                    title = stringResource(R.string.login_email),
                    showDivider = member.user.isDeactivated,
                    trailing = { Text(member.user.email, color = MaterialTheme.colorScheme.onSurfaceVariant) },
                )

                if (member.user.isDeactivated) {
                    ListRow(
                        title = stringResource(R.string.admin_deactivated),
                        leading = { Icon(Icons.Filled.PersonOff, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    )
                }
            }
        }

        item {
            if (member.user.isSuperAdmin) {
                Section {
                    ListRow(title = stringResource(R.string.admin_super_admin_note))
                }
            } else {
                ModuleTogglesSection(catalog.activeModules, modules) { modules = it }
            }
        }

        item {
            Section(header = stringResource(R.string.admin_their_color), footer = stringResource(R.string.admin_their_color_footer)) {
                WorkspaceColorPalette(
                    colors = catalog.colors,
                    selection = color,
                    onSelect = { color = it },
                    allowsWorkspaceColor = true,
                    workspaceColorHex = workspace.colorHex,
                )
            }
        }

        item {
            Section {
                if (member.isDefault) {
                    ListRow(
                        title = stringResource(R.string.admin_their_default),
                        leading = { Icon(Icons.Filled.Star, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    )
                } else {
                    ListRow(
                        title = stringResource(R.string.admin_make_their_default),
                        onClick = { makesDefault = !makesDefault },
                        modifier = Modifier.testTag("admin.member.default"),
                        trailing = { Switch(checked = makesDefault, onCheckedChange = { makesDefault = it }) },
                    )
                }
            }
        }

        failure?.let { error ->
            item { FormError(error.userMessage()) }
        }

        item {
            Section {
                ListRow(
                    title = stringResource(R.string.admin_remove_from_workspace),
                    onClick = { isConfirmingRemove = true },
                    modifier = Modifier.testTag("admin.member.remove"),
                    leading = { Icon(Icons.Filled.PersonRemove, contentDescription = null, tint = MaterialTheme.colorScheme.error) },
                    titleContent = { Text(stringResource(R.string.admin_remove_from_workspace), color = MaterialTheme.colorScheme.error) },
                )
            }
        }
    }

    if (isConfirmingRemove) {
        ConfirmDialog(
            title = stringResource(R.string.admin_remove_member_confirm, member.user.name, workspace.name),
            message = stringResource(R.string.admin_remove_member_description),
            confirmLabel = stringResource(R.string.admin_remove),
            onConfirm = {
                scope.launch {
                    try {
                        model.client.call { removeAdminWorkspaceMember(workspace.id, member.id) }
                        onSaved()
                        onDismiss()
                    } catch (exception: ApiException) {
                        failure = exception
                    }
                }
            },
            onDismiss = { isConfirmingRemove = false },
        )
    }
}

/**
 * Adds an existing user to a workspace — the active users who are not
 * members yet — with their modules there and, optionally, a color of their
 * own for it.
 */
@Composable
fun AdminAddMemberForm(
    model: AppModel,
    workspace: AdminWorkspaceDetail,
    catalog: AdminCatalog,
    onDismiss: () -> Unit,
    onSaved: () -> Unit,
) {
    var users by remember { mutableStateOf<List<AdminUser>?>(null) }
    var userId by remember { mutableStateOf<String?>(null) }
    var modules by remember { mutableStateOf(emptySet<String>()) }
    var color by remember { mutableStateOf<String?>(null) }
    var isSaving by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()
    val memberIds = workspace.members.map { it.id }.toSet()
    val candidates = users?.filter { it.id !in memberIds && !it.isDeactivated }
    val selectedIsSuperAdmin = users?.firstOrNull { it.id == userId }?.isSuperAdmin == true

    LaunchedEffect(Unit) {
        try {
            users = model.client.call { adminUsers() }.data
        } catch (exception: ApiException) {
            users = emptyList()
            failure = exception
        }
    }

    FormSheet(
        title = stringResource(R.string.admin_add_member),
        confirmLabel = stringResource(R.string.add),
        isConfirmEnabled = userId != null && !isSaving,
        onConfirm = {
            val picked = userId ?: return@FormSheet
            val input = AdminMemberInput(user = picked, modules = if (selectedIsSuperAdmin) emptyList() else modules.sorted(), color = color)
            isSaving = true
            scope.launch {
                try {
                    model.client.call { addAdminWorkspaceMember(workspace.id, input) }
                    onSaved()
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
        item {
            Section(header = stringResource(R.string.admin_user)) {
                when {
                    candidates == null -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                    candidates.isEmpty() -> ListRow(title = stringResource(R.string.admin_everyone_is_member))
                    else -> candidates.forEachIndexed { index, user ->
                        ListRow(
                            title = user.name,
                            subtitle = user.email,
                            showDivider = index < candidates.lastIndex,
                            onClick = { userId = user.id },
                            modifier = Modifier.testTag("admin.add-member.user.${user.id}"),
                            trailing = {
                                if (user.id == userId) {
                                    Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                                }
                            },
                        )
                    }
                }
            }
        }

        if (!selectedIsSuperAdmin) {
            item {
                ModuleTogglesSection(catalog.activeModules, modules) { modules = it }
            }
        }

        item {
            Section(header = stringResource(R.string.admin_their_color)) {
                WorkspaceColorPalette(
                    colors = catalog.colors,
                    selection = color,
                    onSelect = { color = it },
                    allowsWorkspaceColor = true,
                    workspaceColorHex = workspace.colorHex,
                )
            }
        }

        failure?.let { error ->
            item { FormError(error.userMessage()) }
        }
    }
}
