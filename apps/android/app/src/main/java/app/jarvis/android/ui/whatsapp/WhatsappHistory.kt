package app.jarvis.android.ui.whatsapp

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Error
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappDelivery
import app.jarvis.android.network.WhatsappDeliveryStatus
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.formatDateTime
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.shell.NoAccessScreen
import kotlinx.coroutines.launch

/**
 * What went out from the user's numbers, newest first (US-7.5): to whom, the
 * text as sent, from which number, and whether it was sent or failed. The next
 * page loads at the end of the list.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun WhatsappHistory(model: AppModel) {
    val scope = rememberCoroutineScope()
    var deliveries by remember { mutableStateOf<List<WhatsappDelivery>?>(null) }
    var page by remember { mutableStateOf(1) }
    var hasMorePages by remember { mutableStateOf(false) }
    var loadFailure by remember { mutableStateOf<ApiException?>(null) }
    var isRefreshing by remember { mutableStateOf(false) }

    suspend fun load(pageToLoad: Int) {
        try {
            val result = model.client.call { whatsappDeliveries(pageToLoad) }
            deliveries = if (pageToLoad == 1) result.data else deliveries.orEmpty() + result.data
            page = result.meta.currentPage
            hasMorePages = result.hasMorePages
            loadFailure = null
        } catch (_: ApiException.Unauthenticated) {
            // The shell already went back to the login.
        } catch (exception: ApiException) {
            hasMorePages = false

            if (deliveries == null) {
                loadFailure = exception
            }
        }
    }

    LaunchedEffect(Unit) {
        load(1)
    }

    PullToRefreshBox(
        isRefreshing = isRefreshing,
        onRefresh = {
            scope.launch {
                isRefreshing = true
                load(1)
                isRefreshing = false
            }
        },
        modifier = Modifier.fillMaxSize(),
    ) {
        val current = deliveries
        val failure = loadFailure

        when {
            current != null && current.isEmpty() -> ContentUnavailable(title = stringResource(R.string.whatsapp_nothing_sent), icon = Icons.AutoMirrored.Filled.Send)
            current != null -> GroupedList {
                item {
                    Section {
                        current.forEachIndexed { index, delivery ->
                            DeliveryRow(model, delivery, showDivider = index < current.lastIndex || hasMorePages)
                        }

                        if (hasMorePages) {
                            Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                                CircularProgressIndicator()
                            }

                            LaunchedEffect(page) { load(page + 1) }
                        }
                    }
                }
            }

            failure is ApiException.Forbidden -> NoAccessScreen(onRetry = { scope.launch { model.refreshUser() } })
            failure != null -> ContentUnavailable(
                title = failure.userMessage(),
                icon = Icons.Filled.WifiOff,
                actionLabel = stringResource(R.string.try_again),
                onAction = { scope.launch { load(1) } },
            )

            else -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        }
    }
}

@Composable
private fun DeliveryRow(model: AppModel, delivery: WhatsappDelivery, showDivider: Boolean) {
    val date = formatDateTime(delivery.sentAt ?: delivery.scheduledFor ?: delivery.createdAt, model.user?.timezone ?: "UTC", model.language.locale)
    val (icon, color, label) = when (delivery.status) {
        WhatsappDeliveryStatus.Pending -> Triple(Icons.Outlined.Schedule, MaterialTheme.colorScheme.tertiary, R.string.whatsapp_status_pending)
        WhatsappDeliveryStatus.Sent -> Triple(Icons.Filled.CheckCircle, MaterialTheme.colorScheme.primary, R.string.whatsapp_status_sent)
        WhatsappDeliveryStatus.Failed -> Triple(Icons.Filled.Error, MaterialTheme.colorScheme.error, R.string.whatsapp_status_failed)
    }

    ListRow(
        title = delivery.target.name,
        showDivider = showDivider,
        leading = { Icon(icon, contentDescription = stringResource(label), tint = color) },
        titleContent = {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(delivery.target.name, style = MaterialTheme.typography.titleSmall, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))

                    if (date != null) {
                        Text(date, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }

                Text(delivery.body, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 3, overflow = TextOverflow.Ellipsis)

                if (delivery.status == WhatsappDeliveryStatus.Failed && !delivery.errorMessage.isNullOrBlank()) {
                    Text(delivery.errorMessage, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error)
                }

                Text(
                    "${delivery.account.nickname} · ${sourceLabel(delivery.source)}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        },
    )
}

@Composable
private fun sourceLabel(source: String): String = when (source) {
    "scheduled" -> stringResource(R.string.whatsapp_source_scheduled)
    "manual" -> stringResource(R.string.whatsapp_source_manual)
    "notification" -> stringResource(R.string.whatsapp_source_notification)
    "voice" -> stringResource(R.string.whatsapp_source_voice)
    else -> source
}
