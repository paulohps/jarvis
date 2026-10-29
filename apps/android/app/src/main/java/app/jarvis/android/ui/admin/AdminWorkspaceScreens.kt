package app.jarvis.android.ui.admin

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.Layers
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.network.AdminWorkspace
import app.jarvis.android.network.AdminWorkspaceDetail
import app.jarvis.android.network.AdminWorkspaceInput
import app.jarvis.android.network.AdminWorkspaceMember
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WorkspaceColorOption
import app.jarvis.android.ui.components.ActionFailureDialog
import app.jarvis.android.ui.components.ConfirmDialog
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.JarvisScreen
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.SectionTextField
import app.jarvis.android.ui.components.WorkspaceColorPalette
import app.jarvis.android.ui.components.WorkspaceDot
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.shell.RemoteContent
import kotlinx.coroutines.launch

/**
 * The super admin's workspaces: each with its color and how many members it
 * has; a new one, and deleting one — refused by the server while it has data,
 * with its message. A workspace opens its members.
 */
@Composable
fun AdminWorkspacesScreen(model: AppModel, onBack: () -> Unit) {
    var isCreating by remember { mutableStateOf(false) }
    var workspaceToDelete by remember { mutableStateOf<AdminWorkspace?>(null) }
    var actionFailure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()

    JarvisScreen(
        title = stringResource(R.string.workspaces),
        onBack = onBack,
        actions = {
            IconButton(onClick = { isCreating = true }, modifier = Modifier.testTag("admin.workspaces.new")) {
                Icon(Icons.Filled.Add, contentDescription = stringResource(R.string.admin_new_workspace))
            }
        },
    ) {
        RemoteContent(key = Unit, load = { model.client.call { adminWorkspaces() }.data }, onRetryAccess = model::refreshUser) { workspaces, reload ->
            if (workspaces.isEmpty()) {
                ContentUnavailable(title = stringResource(R.string.admin_no_workspaces), icon = Icons.Outlined.Layers)
            } else {
                GroupedList {
                    item {
                        Section {
                            workspaces.forEachIndexed { index, workspace ->
                                AdminWorkspaceRow(
                                    workspace = workspace,
                                    showDivider = index < workspaces.lastIndex,
                                    onOpen = { model.router.push(AppTab.Settings, Destination.AdminWorkspace(workspace.id)) },
                                    onDelete = { workspaceToDelete = workspace },
                                )
                            }
                        }
                    }
                }
            }

            if (isCreating) {
                AdminWorkspaceForm(model, workspace = null, onDismiss = { isCreating = false }, onSaved = reload)
            }

            workspaceToDelete?.let { workspace ->
                ConfirmDialog(
                    title = stringResource(R.string.admin_delete_workspace_confirm),
                    message = stringResource(R.string.admin_delete_workspace_description),
                    confirmLabel = stringResource(R.string.delete),
                    onConfirm = {
                        scope.launch {
                            try {
                                model.client.call { deleteAdminWorkspace(workspace.id) }
                                model.refreshUser()
                            } catch (exception: ApiException) {
                                actionFailure = exception
                            }

                            reload()
                        }
                    },
                    onDismiss = { workspaceToDelete = null },
                )
            }
        }
    }

    ActionFailureDialog(actionFailure) { actionFailure = null }
}

@Composable
private fun AdminWorkspaceRow(workspace: AdminWorkspace, showDivider: Boolean, onOpen: () -> Unit, onDelete: () -> Unit) {
    var isMenuOpen by remember { mutableStateOf(false) }

    ListRow(
        title = workspace.name,
        subtitle = pluralStringResource(R.plurals.admin_members_count, workspace.membersCount, workspace.membersCount),
        showDivider = showDivider,
        onClick = onOpen,
        modifier = Modifier.testTag("admin.workspace.${workspace.id}"),
        leading = { WorkspaceDot(workspace.colorHex, size = 14.dp) },
        trailing = {
            Box {
                IconButton(onClick = { isMenuOpen = true }) {
                    Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }

                DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }) {
                    DropdownMenuItem(
                        text = { Text(stringResource(R.string.delete), color = MaterialTheme.colorScheme.error) },
                        onClick = {
                            isMenuOpen = false
                            onDelete()
                        },
                    )
                }
            }

            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
        },
    )
}

/**
 * A workspace and its members: their modules there, their color and whether
 * it is their default (a member opens to change them, or to be removed);
 * adding an existing user; renaming, recoloring and deleting it. After a
 * change `/me` is read again: the admin may have changed their own membership.
 */
@Composable
fun AdminWorkspaceScreen(model: AppModel, workspaceId: String, onBack: () -> Unit) {
    var detail by remember(workspaceId) { mutableStateOf<AdminWorkspaceDetail?>(null) }
    var catalog by remember { mutableStateOf(AdminCatalog()) }
    var isEditing by remember { mutableStateOf(false) }
    var isAddingMember by remember { mutableStateOf(false) }
    var memberToEdit by remember { mutableStateOf<AdminWorkspaceMember?>(null) }
    var isConfirmingDelete by remember { mutableStateOf(false) }
    var actionFailure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()

    JarvisScreen(
        title = detail?.name ?: stringResource(R.string.workspace),
        onBack = onBack,
        actions = {
            if (detail != null) {
                IconButton(onClick = { isEditing = true }, modifier = Modifier.testTag("admin.workspace.edit")) {
                    Icon(Icons.Filled.Edit, contentDescription = stringResource(R.string.admin_edit_workspace))
                }
            }
        },
    ) {
        RemoteContent(
            key = workspaceId,
            load = {
                if (catalog.modules.isEmpty()) {
                    catalog = AdminCatalog.load(model.client)
                }

                model.client.call { adminWorkspace(workspaceId) }.data.also { detail = it }
            },
            onRetryAccess = model::refreshUser,
        ) { workspace, reload ->
            val changed: () -> Unit = {
                reload()
                scope.launch { model.refreshUser() }
            }

            GroupedList {
                item {
                    Section {
                        ListRow(
                            title = workspace.name,
                            leading = { WorkspaceDot(workspace.colorHex, size = 16.dp) },
                            titleContent = { Text(workspace.name, style = MaterialTheme.typography.titleMedium) },
                        )
                    }
                }

                item {
                    Section(header = stringResource(R.string.admin_members)) {
                        workspace.members.forEach { member ->
                            AdminMemberRow(member, catalog, onClick = { memberToEdit = member })
                        }

                        ListRow(
                            title = stringResource(R.string.admin_add_member),
                            onClick = { isAddingMember = true },
                            modifier = Modifier.testTag("admin.workspace.add-member"),
                            leading = { Icon(Icons.Filled.PersonAdd, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
                            titleContent = { Text(stringResource(R.string.admin_add_member), color = MaterialTheme.colorScheme.primary) },
                        )
                    }
                }

                item {
                    Section(footer = stringResource(R.string.admin_workspace_has_data).takeIf { workspace.hasData }) {
                        ListRow(
                            title = stringResource(R.string.admin_delete_workspace),
                            onClick = { isConfirmingDelete = true },
                            modifier = Modifier.testTag("admin.workspace.delete"),
                            leading = { Icon(Icons.Filled.Delete, contentDescription = null, tint = MaterialTheme.colorScheme.error) },
                            titleContent = { Text(stringResource(R.string.admin_delete_workspace), color = MaterialTheme.colorScheme.error) },
                        )
                    }
                }
            }

            if (isEditing) {
                AdminWorkspaceForm(model, workspace, onDismiss = { isEditing = false }, onSaved = changed)
            }

            if (isAddingMember) {
                AdminAddMemberForm(model, workspace, catalog, onDismiss = { isAddingMember = false }, onSaved = changed)
            }

            memberToEdit?.let { member ->
                AdminMemberForm(model, workspace, member, catalog, onDismiss = { memberToEdit = null }, onSaved = changed)
            }
        }
    }

    if (isConfirmingDelete) {
        ConfirmDialog(
            title = stringResource(R.string.admin_delete_workspace_confirm),
            message = stringResource(R.string.admin_delete_workspace_description),
            confirmLabel = stringResource(R.string.delete),
            onConfirm = {
                scope.launch {
                    try {
                        model.client.call { deleteAdminWorkspace(workspaceId) }
                        model.refreshUser()
                        onBack()
                    } catch (exception: ApiException) {
                        actionFailure = exception
                    }
                }
            },
            onDismiss = { isConfirmingDelete = false },
        )
    }

    ActionFailureDialog(actionFailure) { actionFailure = null }
}

/** A member: their name, their color there, their modules and whether it is their default. */
@Composable
private fun AdminMemberRow(member: AdminWorkspaceMember, catalog: AdminCatalog, onClick: () -> Unit) {
    val modules = when {
        member.user.isSuperAdmin -> stringResource(R.string.admin_super_admin_every_module)
        else -> catalog.moduleNames(member.modules) ?: stringResource(R.string.admin_no_modules)
    }

    ListRow(
        title = member.user.name,
        subtitle = modules,
        showDivider = true,
        onClick = onClick,
        modifier = Modifier.testTag("admin.member.${member.id}"),
        leading = { WorkspaceDot(member.effectiveColorHex, size = 14.dp) },
        titleContent = {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(
                    member.user.name,
                    style = MaterialTheme.typography.bodyLarge,
                    textDecoration = if (member.user.isDeactivated) TextDecoration.LineThrough else null,
                )

                if (member.isDefault) {
                    Icon(
                        Icons.Filled.Star,
                        contentDescription = stringResource(R.string.workspace_default),
                        tint = MaterialTheme.colorScheme.tertiary,
                        modifier = Modifier.size(16.dp),
                    )
                }
            }
        },
        trailing = { Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
    )
}

/** A new workspace (name and color) or an edit of one. */
@Composable
fun AdminWorkspaceForm(model: AppModel, workspace: AdminWorkspaceDetail?, onDismiss: () -> Unit, onSaved: () -> Unit) {
    var name by rememberSaveable { mutableStateOf(workspace?.name.orEmpty()) }
    var color by rememberSaveable { mutableStateOf(workspace?.color) }
    var colors by remember { mutableStateOf<List<WorkspaceColorOption>?>(null) }
    var isSaving by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        try {
            val loaded = model.client.call { workspaceColors() }.data
            colors = loaded

            if (color == null) {
                color = loaded.firstOrNull()?.name
            }
        } catch (exception: ApiException) {
            colors = emptyList()
            failure = exception
        }
    }

    FormSheet(
        title = stringResource(if (workspace == null) R.string.admin_new_workspace else R.string.admin_edit_workspace),
        confirmLabel = stringResource(R.string.save),
        isConfirmEnabled = name.isNotBlank() && color != null && !isSaving,
        onConfirm = {
            val picked = color ?: return@FormSheet
            val input = AdminWorkspaceInput(name.trim(), picked)
            isSaving = true
            scope.launch {
                try {
                    model.client.call {
                        if (workspace != null) updateAdminWorkspace(workspace.id, input) else createAdminWorkspace(input)
                    }
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
                SectionTextField(name, { name = it }, stringResource(R.string.name), Modifier.testTag("admin.workspace.name"))
            }
        }

        item {
            Section(header = stringResource(R.string.workspace_color)) {
                val loaded = colors

                if (loaded == null) {
                    Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                } else {
                    WorkspaceColorPalette(loaded, color, { picked -> color = picked })
                }
            }
        }

        failure?.let { error ->
            item { FormError(error.userMessage()) }
        }
    }
}
