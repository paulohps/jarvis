package app.jarvis.android.ui.admin

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.outlined.MailOutline
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.i18n.AppLanguage
import app.jarvis.android.network.AdminInvitation
import app.jarvis.android.network.AdminInvitationInput
import app.jarvis.android.network.AdminWorkspace
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.InvitationStatus
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
import app.jarvis.android.ui.components.WorkspaceBadge
import app.jarvis.android.ui.components.WorkspaceDot
import app.jarvis.android.ui.components.formatDateTime
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.shell.RemoteContent
import kotlinx.coroutines.launch

/**
 * The invitations, for the super admin: who, to which workspace, their state;
 * a new one, and sending again or revoking an open one.
 */
@Composable
fun AdminInvitationsScreen(model: AppModel, onBack: () -> Unit) {
    var catalog by remember { mutableStateOf(AdminCatalog()) }
    var isCreating by remember { mutableStateOf(false) }
    var invitationToRevoke by remember { mutableStateOf<AdminInvitation?>(null) }
    var resentEmail by remember { mutableStateOf<String?>(null) }
    var actionFailure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()

    JarvisScreen(
        title = stringResource(R.string.admin_invitations),
        onBack = onBack,
        actions = {
            IconButton(onClick = { isCreating = true }, modifier = Modifier.testTag("admin.invitations.new")) {
                Icon(Icons.Filled.Add, contentDescription = stringResource(R.string.admin_new_invitation))
            }
        },
    ) {
        RemoteContent(
            key = Unit,
            load = {
                if (catalog.modules.isEmpty()) {
                    catalog = AdminCatalog.load(model.client)
                }

                model.client.call { adminInvitations() }.data
            },
            onRetryAccess = model::refreshUser,
        ) { invitations, reload ->
            val run: (suspend () -> Unit) -> Unit = { action ->
                scope.launch {
                    try {
                        action()
                    } catch (exception: ApiException) {
                        actionFailure = exception
                    }

                    reload()
                }
            }

            if (invitations.isEmpty()) {
                ContentUnavailable(title = stringResource(R.string.admin_no_invitations), icon = Icons.Outlined.MailOutline)
            } else {
                GroupedList {
                    item {
                        Section {
                            invitations.forEachIndexed { index, invitation ->
                                AdminInvitationRow(
                                    model = model,
                                    invitation = invitation,
                                    catalog = catalog,
                                    showDivider = index < invitations.lastIndex,
                                    onResend = {
                                        run {
                                            model.client.call { resendAdminInvitation(invitation.id) }
                                            resentEmail = invitation.email
                                        }
                                    },
                                    onRevoke = { invitationToRevoke = invitation },
                                )
                            }
                        }
                    }
                }
            }

            if (isCreating) {
                AdminInvitationForm(model, catalog, onDismiss = { isCreating = false }, onSaved = reload)
            }

            invitationToRevoke?.let { invitation ->
                ConfirmDialog(
                    title = stringResource(R.string.admin_revoke_invitation_confirm),
                    message = stringResource(R.string.admin_revoke_invitation_description, invitation.email),
                    confirmLabel = stringResource(R.string.admin_revoke),
                    onConfirm = { run { model.client.call { revokeAdminInvitation(invitation.id) } } },
                    onDismiss = { invitationToRevoke = null },
                )
            }
        }
    }

    resentEmail?.let { email ->
        AlertDialog(
            onDismissRequest = { resentEmail = null },
            title = { Text(stringResource(R.string.admin_invitation_resent)) },
            text = { Text(email) },
            confirmButton = { TextButton(onClick = { resentEmail = null }) { Text(stringResource(R.string.ok)) } },
        )
    }

    ActionFailureDialog(actionFailure) { actionFailure = null }
}

/** An invitation: the email, its state, the workspace, the modules and until when it can be accepted. */
@Composable
private fun AdminInvitationRow(
    model: AppModel,
    invitation: AdminInvitation,
    catalog: AdminCatalog,
    showDivider: Boolean,
    onResend: () -> Unit,
    onRevoke: () -> Unit,
) {
    var isMenuOpen by remember { mutableStateOf(false) }
    val expiresAt = formatDateTime(invitation.expiresAt, model.user?.timezone ?: "UTC", model.language.locale)?.takeIf { invitation.isPending }

    ListRow(
        title = invitation.email,
        showDivider = showDivider,
        modifier = Modifier.testTag("admin.invitation.${invitation.id}"),
        titleContent = {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        invitation.email,
                        style = MaterialTheme.typography.bodyLarge,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f, fill = false),
                    )
                    Text(
                        invitationStatusText(invitation),
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.SemiBold,
                        color = invitationStatusColor(invitation.knownStatus),
                    )
                }

                invitation.workspace?.let { WorkspaceBadge(it) }

                catalog.moduleNames(invitation.modules)?.let { modules ->
                    Text(modules, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 2)
                }

                if (expiresAt != null) {
                    Text(stringResource(R.string.admin_expires, expiresAt), style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        },
        trailing = if (invitation.isPending) {
            {
                Box {
                    IconButton(onClick = { isMenuOpen = true }, modifier = Modifier.testTag("admin.invitation.${invitation.id}.menu")) {
                        Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                    }

                    DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }) {
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.admin_resend)) },
                            onClick = {
                                isMenuOpen = false
                                onResend()
                            },
                        )
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.admin_revoke), color = MaterialTheme.colorScheme.error) },
                            onClick = {
                                isMenuOpen = false
                                onRevoke()
                            },
                        )
                    }
                }
            }
        } else {
            null
        },
    )
}

@Composable
private fun invitationStatusText(invitation: AdminInvitation): String = when (invitation.knownStatus) {
    InvitationStatus.Pending -> stringResource(R.string.admin_status_pending)
    InvitationStatus.Accepted -> stringResource(R.string.admin_status_accepted)
    InvitationStatus.Expired -> stringResource(R.string.admin_status_expired)
    InvitationStatus.Revoked -> stringResource(R.string.admin_status_revoked)
    null -> invitation.status
}

@Composable
private fun invitationStatusColor(status: InvitationStatus?): Color = when (status) {
    InvitationStatus.Pending -> MaterialTheme.colorScheme.tertiary
    InvitationStatus.Accepted -> MaterialTheme.colorScheme.primary
    InvitationStatus.Expired, InvitationStatus.Revoked, null -> MaterialTheme.colorScheme.onSurfaceVariant
}

/** A new invitation: the email, the workspace the person joins, their modules there and the language of the email. */
@Composable
private fun AdminInvitationForm(model: AppModel, catalog: AdminCatalog, onDismiss: () -> Unit, onSaved: () -> Unit) {
    var email by remember { mutableStateOf("") }
    var locale by remember { mutableStateOf(AppLanguage.BrazilianPortuguese) }
    var workspaces by remember { mutableStateOf<List<AdminWorkspace>?>(null) }
    var workspaceId by remember { mutableStateOf<String?>(null) }
    var modules by remember { mutableStateOf(emptySet<String>()) }
    var isSaving by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        try {
            val loaded = model.client.call { adminWorkspaces() }.data
            workspaces = loaded

            if (workspaceId == null) {
                val preferred = model.user?.workspaces?.firstOrNull { it.isDefault }?.id
                workspaceId = loaded.firstOrNull { it.id == preferred }?.id ?: loaded.firstOrNull()?.id
            }
        } catch (exception: ApiException) {
            workspaces = emptyList()
            failure = exception
        }
    }

    FormSheet(
        title = stringResource(R.string.admin_new_invitation),
        confirmLabel = stringResource(R.string.send),
        isConfirmEnabled = email.isNotBlank() && workspaceId != null && !isSaving,
        onConfirm = {
            val picked = workspaceId ?: return@FormSheet
            val input = AdminInvitationInput(email.trim(), picked, modules.sorted(), locale.apiLocale)
            isSaving = true
            scope.launch {
                try {
                    model.client.call { createAdminInvitation(input) }
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
                SectionTextField(
                    email,
                    { email = it },
                    stringResource(R.string.login_email),
                    Modifier.testTag("admin.invitation.email"),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                )
            }
        }

        item {
            Section(header = stringResource(R.string.admin_email_language)) {
                AppLanguage.entries.forEachIndexed { index, option ->
                    ListRow(
                        title = option.nativeName,
                        showDivider = index < AppLanguage.entries.lastIndex,
                        onClick = { locale = option },
                        modifier = Modifier.testTag("admin.invitation.locale.${option.apiLocale}"),
                        trailing = {
                            if (option == locale) {
                                Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                            }
                        },
                    )
                }
            }
        }

        item {
            Section(header = stringResource(R.string.workspace)) {
                val loaded = workspaces

                if (loaded == null) {
                    Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                } else {
                    loaded.forEachIndexed { index, workspace ->
                        ListRow(
                            title = workspace.name,
                            showDivider = index < loaded.lastIndex,
                            onClick = { workspaceId = workspace.id },
                            modifier = Modifier.testTag("admin.invitation.workspace.${workspace.id}"),
                            leading = { WorkspaceDot(workspace.colorHex, size = 14.dp) },
                            trailing = {
                                if (workspace.id == workspaceId) {
                                    Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                                }
                            },
                        )
                    }
                }
            }
        }

        item {
            ModuleTogglesSection(catalog.activeModules, modules) { modules = it }
        }

        failure?.let { error ->
            item { FormError(error.userMessage()) }
        }
    }
}
