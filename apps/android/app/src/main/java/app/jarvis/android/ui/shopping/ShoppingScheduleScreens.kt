package app.jarvis.android.ui.shopping

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.ui.unit.dp
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Autorenew
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.UnfoldMore
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.ModuleSlug
import app.jarvis.android.network.defaultWorkspace
import app.jarvis.android.network.workspacePickerOptions
import app.jarvis.android.network.ShoppingListNextContent
import app.jarvis.android.network.ShoppingListSchedule
import app.jarvis.android.network.ShoppingListScheduleInput
import app.jarvis.android.ui.components.ActionFailureDialog
import app.jarvis.android.ui.components.ConfirmDialog
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.SectionTextField
import app.jarvis.android.ui.components.WorkspaceBadge
import app.jarvis.android.ui.components.WorkspacePickerSection
import app.jarvis.android.ui.components.observedUser
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.schedule.ScheduleDraft
import app.jarvis.android.ui.schedule.ScheduleEditor
import app.jarvis.android.ui.schedule.WallClock
import app.jarvis.android.ui.schedule.recurrenceSummary
import app.jarvis.android.ui.shell.RemoteContent
import java.time.ZonedDateTime
import kotlinx.coroutines.launch

/**
 * The household's recurring shopping lists, as the web's "Recurring" tab: by
 * name, with their schedule and the deadline of their open list; a tap edits
 * one, the menu stops it. [isCreating] opens the form for a new one. With
 * [showsWorkspace] (two or more workspaces with shopping), each shows its
 * workspace's dot and name.
 */
@Composable
fun ShoppingSchedules(model: AppModel, isCreating: Boolean, onCreatingDone: () -> Unit, showsWorkspace: Boolean = false) {
    var scheduleToEdit by remember { mutableStateOf<ShoppingListSchedule?>(null) }
    var scheduleToStop by remember { mutableStateOf<ShoppingListSchedule?>(null) }
    var actionFailure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()

    RemoteContent(key = "schedules", load = { model.client.call { shoppingSchedules() }.data }, onRetryAccess = model::refreshUser) { schedules, reload ->
        if (schedules.isEmpty()) {
            ContentUnavailable(
                title = stringResource(R.string.shopping_no_schedules),
                icon = Icons.Filled.Autorenew,
                description = stringResource(R.string.shopping_schedules_description),
            )
        } else {
            GroupedList {
                item {
                    Section {
                        schedules.forEachIndexed { index, schedule ->
                            ShoppingScheduleRow(
                                schedule = schedule,
                                model = model,
                                showDivider = index < schedules.lastIndex,
                                showsWorkspace = showsWorkspace,
                                onEdit = { scheduleToEdit = schedule },
                                onStop = { scheduleToStop = schedule },
                            )
                        }
                    }
                }
            }
        }

        scheduleToStop?.let { schedule ->
            ConfirmDialog(
                title = stringResource(R.string.shopping_stop_schedule_confirm),
                message = stringResource(R.string.shopping_stop_schedule_description),
                confirmLabel = stringResource(R.string.shopping_stop),
                onConfirm = {
                    scope.launch {
                        try {
                            model.client.call { deleteShoppingSchedule(schedule.friendlyId) }
                        } catch (exception: ApiException) {
                            actionFailure = exception
                        }

                        reload()
                    }
                },
                onDismiss = { scheduleToStop = null },
            )
        }

        if (isCreating) {
            ShoppingScheduleForm(model, schedule = null, onDismiss = onCreatingDone, onSave = reload)
        }

        scheduleToEdit?.let { schedule ->
            ShoppingScheduleForm(model, schedule, onDismiss = { scheduleToEdit = null }, onSave = reload)
        }
    }

    ActionFailureDialog(actionFailure) { actionFailure = null }
}

@Composable
private fun ShoppingScheduleRow(
    schedule: ShoppingListSchedule,
    model: AppModel,
    showDivider: Boolean,
    showsWorkspace: Boolean,
    onEdit: () -> Unit,
    onStop: () -> Unit,
) {
    var isMenuOpen by remember { mutableStateOf(false) }
    val openList = schedule.openList
    val deadline = openList?.dueAt?.let { deadlineText(it, openList.isOverdue, model) }
    val summary = schedule.recurrence?.let { recurrenceSummary(it, model.language.locale) }
    val workspace = schedule.workspace?.takeIf { showsWorkspace }

    ListRow(
        title = schedule.name,
        subtitle = listOfNotNull(summary, deadline).joinToString("\n").ifEmpty { null },
        subtitleColor = MaterialTheme.colorScheme.error.takeIf { openList?.isOverdue == true },
        showDivider = showDivider,
        onClick = onEdit,
        modifier = Modifier.testTag("shopping.schedule.${schedule.friendlyId}"),
        titleContent = if (workspace != null) {
            {
                Column {
                    Text(schedule.name, style = MaterialTheme.typography.bodyLarge)
                    WorkspaceBadge(workspace, Modifier.padding(top = 2.dp))
                }
            }
        } else {
            null
        },
        trailing = {
            Box {
                IconButton(onClick = { isMenuOpen = true }) {
                    Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }

                DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }) {
                    DropdownMenuItem(
                        text = { Text(stringResource(R.string.shopping_stop), color = MaterialTheme.colorScheme.error) },
                        onClick = {
                            isMenuOpen = false
                            onStop()
                        },
                    )
                }
            }
        },
    )
}

/**
 * Creates a recurring shopping list, or edits one, as the web form: its name,
 * its schedule — each run is a list's deadline — what the next list starts
 * with (the template typed one item per line), and how a list closes: by
 * itself at its deadline, or by hand, optionally holding the next one back
 * until then. For a user in two or more workspaces with shopping, also where
 * its lists are made (the default workspace on a new one); changing it on an
 * edit moves it. The server checks it; a refusal comes translated under the form.
 */
@Composable
fun ShoppingScheduleForm(model: AppModel, schedule: ShoppingListSchedule?, onDismiss: () -> Unit, onSave: () -> Unit) {
    val zone = WallClock.zone(model.user?.timezone)
    var name by rememberSaveable { mutableStateOf(schedule?.name.orEmpty()) }
    var draft by remember {
        mutableStateOf(
            if (schedule != null) {
                ScheduleDraft.of(schedule.recurrence, zone)
            } else {
                ScheduleDraft.new(ScheduleDraft.Kind.Weekly, ZonedDateTime.now(zone))
            },
        )
    }
    var nextListContent by rememberSaveable { mutableStateOf(schedule?.nextListContent ?: ShoppingListNextContent.Pending) }
    var template by rememberSaveable { mutableStateOf(schedule?.templateItems.orEmpty().joinToString("\n")) }
    var closesAutomatically by rememberSaveable { mutableStateOf(schedule?.closesAutomatically ?: true) }
    var waitsForClose by rememberSaveable { mutableStateOf(schedule?.waitsForClose ?: false) }
    var isSaving by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()
    val user = model.observedUser()
    // None (no picker) with shopping in one workspace only.
    val workspaceOptions = user?.workspacePickerOptions(ModuleSlug.SHOPPING).orEmpty()
    var workspaceId by rememberSaveable { mutableStateOf(schedule?.workspace?.id ?: user?.defaultWorkspace(ModuleSlug.SHOPPING)?.id) }

    FormSheet(
        title = stringResource(if (schedule == null) R.string.shopping_new_schedule else R.string.shopping_edit_schedule),
        confirmLabel = stringResource(R.string.save),
        isConfirmEnabled = name.isNotBlank() && !isSaving,
        onConfirm = {
            val recurrence = draft.recurrenceInput() ?: return@FormSheet
            val input = ShoppingListScheduleInput(
                name = name.trim(),
                recurrence = recurrence,
                nextListContent = nextListContent,
                closesAutomatically = closesAutomatically,
                waitsForClose = !closesAutomatically && waitsForClose,
                templateItems = ShoppingListScheduleInput.templateItems(template),
                workspace = workspaceId.takeIf { workspaceOptions.isNotEmpty() },
            )
            isSaving = true
            scope.launch {
                try {
                    model.client.call {
                        if (schedule != null) updateShoppingSchedule(schedule.friendlyId, input) else createShoppingSchedule(input)
                    }
                    onSave()
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
                SectionTextField(name, { name = it }, stringResource(R.string.shopping_schedule_name_placeholder), Modifier.testTag("shopping.schedule.name"))
            }
        }

        if (workspaceOptions.isNotEmpty()) {
            item {
                WorkspacePickerSection(workspaceOptions, workspaceId) { workspaceId = it }
            }
        }

        item {
            ScheduleEditor(draft, ScheduleDraft.Kind.entries - ScheduleDraft.Kind.None, model.language.locale) { draft = it }
        }

        item {
            Section(footer = stringResource(R.string.shopping_template_hint).takeIf { nextListContent == ShoppingListNextContent.TemplateAndPending }) {
                NextContentRow(nextListContent, showDivider = nextListContent == ShoppingListNextContent.TemplateAndPending) { nextListContent = it }

                if (nextListContent == ShoppingListNextContent.TemplateAndPending) {
                    SectionTextField(
                        template,
                        { template = it },
                        stringResource(R.string.shopping_template),
                        Modifier.testTag("shopping.schedule.template"),
                        singleLine = false,
                        minLines = 3,
                    )
                }
            }
        }

        item {
            Section(footer = stringResource(R.string.shopping_schedule_deadline_hint)) {
                ListRow(
                    title = stringResource(R.string.shopping_closes_automatically),
                    showDivider = !closesAutomatically,
                    onClick = { closesAutomatically = !closesAutomatically },
                    trailing = { Switch(checked = closesAutomatically, onCheckedChange = { closesAutomatically = it }) },
                )

                if (!closesAutomatically) {
                    ListRow(
                        title = stringResource(R.string.shopping_waits_for_close),
                        onClick = { waitsForClose = !waitsForClose },
                        trailing = { Switch(checked = waitsForClose, onCheckedChange = { waitsForClose = it }) },
                    )
                }
            }
        }

        failure?.let { error ->
            item { FormError(error.userMessage()) }
        }
    }
}

/** What the next list starts with: one of [ShoppingListNextContent]. */
@Composable
private fun NextContentRow(selected: ShoppingListNextContent, showDivider: Boolean, onSelect: (ShoppingListNextContent) -> Unit) {
    var isMenuOpen by remember { mutableStateOf(false) }

    Box {
        ListRow(
            title = stringResource(R.string.shopping_next_list_starts_with),
            subtitle = stringResource(selected.title),
            subtitleColor = MaterialTheme.colorScheme.primary,
            showDivider = showDivider,
            onClick = { isMenuOpen = true },
            modifier = Modifier.testTag("shopping.schedule.next"),
            trailing = { Icon(Icons.Filled.UnfoldMore, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
        )

        DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }, modifier = Modifier.align(Alignment.CenterEnd)) {
            ShoppingListNextContent.entries.forEach { content ->
                DropdownMenuItem(
                    text = { Text(stringResource(content.title)) },
                    onClick = {
                        isMenuOpen = false
                        onSelect(content)
                    },
                )
            }
        }
    }
}

private val ShoppingListNextContent.title: Int
    get() = when (this) {
        ShoppingListNextContent.Pending -> R.string.shopping_next_pending
        ShoppingListNextContent.All -> R.string.shopping_next_all
        ShoppingListNextContent.TemplateAndPending -> R.string.shopping_next_template_and_pending
        ShoppingListNextContent.Empty -> R.string.shopping_next_empty
    }
