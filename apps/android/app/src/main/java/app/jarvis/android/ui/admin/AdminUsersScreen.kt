package app.jarvis.android.ui.admin

import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.outlined.Group
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.network.AdminUser
import app.jarvis.android.network.ApiException
import app.jarvis.android.ui.components.ActionFailureDialog
import app.jarvis.android.ui.components.ConfirmDialog
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.JarvisScreen
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.WorkspaceBadge
import app.jarvis.android.ui.shell.RemoteContent
import kotlinx.coroutines.launch

/**
 * The users, for the super admin: each with the workspaces they are in;
 * deactivating one (signed out, no more access, after a confirmation) or
 * reactivating them. Not on the admin's own row.
 */
@Composable
fun AdminUsersScreen(model: AppModel, onBack: () -> Unit) {
    var userToDeactivate by remember { mutableStateOf<AdminUser?>(null) }
    var actionFailure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()
    val currentUserId = model.user?.friendlyId

    JarvisScreen(title = stringResource(R.string.admin_users), onBack = onBack) {
        RemoteContent(key = Unit, load = { model.client.call { adminUsers() }.data }, onRetryAccess = model::refreshUser) { users, reload ->
            val setActive: (AdminUser, Boolean) -> Unit = { user, isActive ->
                scope.launch {
                    try {
                        model.client.call { if (isActive) reactivateAdminUser(user.id) else deactivateAdminUser(user.id) }
                    } catch (exception: ApiException) {
                        actionFailure = exception
                    }

                    reload()
                }
            }

            if (users.isEmpty()) {
                ContentUnavailable(title = stringResource(R.string.admin_no_users), icon = Icons.Outlined.Group)
            } else {
                GroupedList {
                    item {
                        Section {
                            users.forEachIndexed { index, user ->
                                AdminUserRow(
                                    user = user,
                                    canChange = user.id != currentUserId,
                                    showDivider = index < users.lastIndex,
                                    onDeactivate = { userToDeactivate = user },
                                    onReactivate = { setActive(user, true) },
                                )
                            }
                        }
                    }
                }
            }

            userToDeactivate?.let { user ->
                ConfirmDialog(
                    title = stringResource(R.string.admin_deactivate_confirm, user.name),
                    message = stringResource(R.string.admin_deactivate_description),
                    confirmLabel = stringResource(R.string.admin_deactivate),
                    onConfirm = { setActive(user, false) },
                    onDismiss = { userToDeactivate = null },
                )
            }
        }
    }

    ActionFailureDialog(actionFailure) { actionFailure = null }
}

/** A user: name, email, their workspaces and whether they are a super admin or deactivated. */
@Composable
private fun AdminUserRow(
    user: AdminUser,
    canChange: Boolean,
    showDivider: Boolean,
    onDeactivate: () -> Unit,
    onReactivate: () -> Unit,
) {
    var isMenuOpen by remember { mutableStateOf(false) }

    ListRow(
        title = user.name,
        showDivider = showDivider,
        modifier = Modifier.testTag("admin.user.${user.id}"),
        titleContent = {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(
                        user.name,
                        style = MaterialTheme.typography.bodyLarge,
                        textDecoration = if (user.isDeactivated) TextDecoration.LineThrough else null,
                    )

                    if (user.isSuperAdmin) {
                        Text(
                            stringResource(R.string.admin_super_admin),
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.SemiBold,
                            color = MaterialTheme.colorScheme.onPrimaryContainer,
                            modifier = Modifier
                                .background(MaterialTheme.colorScheme.primaryContainer, MaterialTheme.shapes.small)
                                .padding(horizontal = 6.dp, vertical = 2.dp),
                        )
                    }

                    if (user.isDeactivated) {
                        Text(
                            stringResource(R.string.admin_deactivated),
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.SemiBold,
                            color = MaterialTheme.colorScheme.error,
                        )
                    }
                }

                Text(user.email, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)

                if (user.workspaces.isEmpty()) {
                    Text(stringResource(R.string.admin_no_workspace), style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                } else {
                    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        user.workspaces.forEach { workspace -> WorkspaceBadge(workspace) }
                    }
                }
            }
        },
        trailing = if (canChange) {
            {
                Box {
                    IconButton(onClick = { isMenuOpen = true }, modifier = Modifier.testTag("admin.user.${user.id}.menu")) {
                        Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                    }

                    DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }) {
                        if (user.isDeactivated) {
                            DropdownMenuItem(
                                text = { Text(stringResource(R.string.admin_reactivate)) },
                                onClick = {
                                    isMenuOpen = false
                                    onReactivate()
                                },
                            )
                        } else {
                            DropdownMenuItem(
                                text = { Text(stringResource(R.string.admin_deactivate), color = MaterialTheme.colorScheme.error) },
                                onClick = {
                                    isMenuOpen = false
                                    onDeactivate()
                                },
                            )
                        }
                    }
                }
            }
        } else {
            null
        },
    )
}
