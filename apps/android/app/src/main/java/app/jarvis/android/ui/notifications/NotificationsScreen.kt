package app.jarvis.android.ui.notifications

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material.icons.outlined.NotificationsNone
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
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
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.jarvis.android.R
import app.jarvis.android.app.AppContainer
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.NotificationItem
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.JarvisScreen
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.formatDateTime
import kotlinx.coroutines.launch

/**
 * The in-app notifications (US-5.1), newest first: the unread ones marked,
 * new ones arriving in real time while the screen is open, "mark all as
 * read", and a tap marks it read and opens its link in the app.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NotificationsScreen(container: AppContainer) {
    val store = container.notifications
    val state by store.state.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()
    var isRefreshing by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        if (!state.hasLoaded) {
            store.reload()
        }
    }

    JarvisScreen(
        title = stringResource(R.string.tab_notifications),
        actions = {
            if (state.unreadCount > 0) {
                TextButton(onClick = { scope.launch { store.markAllRead() } }, modifier = Modifier.testTag("notifications.read-all")) {
                    Text(stringResource(R.string.notifications_mark_all_read))
                }
            }
        },
    ) {
        PullToRefreshBox(
            isRefreshing = isRefreshing,
            onRefresh = {
                scope.launch {
                    isRefreshing = true
                    store.reload()
                    isRefreshing = false
                }
            },
            modifier = Modifier.fillMaxSize(),
        ) {
            when {
                !state.hasLoaded -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }

                state.items.isEmpty() && state.failure != null -> ContentUnavailable(
                    title = stringResource(if (state.failure is ApiException.Transport) R.string.error_unreachable else R.string.error_generic),
                    icon = Icons.Filled.WifiOff,
                    actionLabel = stringResource(R.string.try_again),
                    onAction = { scope.launch { store.reload() } },
                )

                state.items.isEmpty() -> ContentUnavailable(
                    title = stringResource(R.string.notifications_empty_title),
                    icon = Icons.Outlined.NotificationsNone,
                    description = stringResource(R.string.notifications_empty_description),
                )

                else -> GroupedList {
                    item {
                        Section {
                            state.items.forEachIndexed { index, item ->
                                NotificationRow(container, item, showDivider = index < state.items.lastIndex) {
                                    scope.launch { store.markRead(item.id) }
                                    container.model.open(item.url ?: item.actions.firstOrNull()?.url)
                                }
                            }
                        }
                    }

                    if (state.hasMorePages) {
                        item {
                            LaunchedEffect(state.items.size) { store.loadMore() }
                            Box(Modifier.fillMaxWidth().padding(8.dp), contentAlignment = Alignment.Center) {
                                CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp)
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun NotificationRow(container: AppContainer, item: NotificationItem, showDivider: Boolean, onClick: () -> Unit) {
    val date = formatDateTime(item.createdAt, container.model.user?.timezone ?: "UTC", container.model.language.locale)

    ListRow(
        title = item.title,
        showDivider = showDivider,
        onClick = onClick,
        leading = {
            Box(
                Modifier
                    .size(10.dp)
                    .background(if (item.isRead) Color.Transparent else MaterialTheme.colorScheme.primary, CircleShape),
            )
        },
        titleContent = {
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Top) {
                    Text(
                        item.title,
                        style = MaterialTheme.typography.bodyLarge,
                        fontWeight = if (item.isRead) FontWeight.Normal else FontWeight.SemiBold,
                        modifier = Modifier.weight(1f),
                    )

                    if (date != null) {
                        Text(date, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }

                item.plainBody?.let {
                    Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 4)
                }
            }
        },
    )
}
