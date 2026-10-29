package app.jarvis.android.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import app.jarvis.android.R

/** The room left under a list so its last row clears the microphone button. */
val FabClearance = 88.dp

/**
 * A page of a tab: a Material top app bar (with the back arrow when pushed)
 * and its trailing actions, then the content.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun JarvisScreen(
    title: String,
    modifier: Modifier = Modifier,
    onBack: (() -> Unit)? = null,
    actions: @Composable RowScope.() -> Unit = {},
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(modifier.fillMaxSize().background(MaterialTheme.colorScheme.surface)) {
        TopAppBar(
            title = { Text(title, maxLines = 1) },
            navigationIcon = {
                if (onBack != null) {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.back))
                    }
                }
            },
            actions = actions,
        )

        content()
    }
}

/**
 * A scrolling list of sections that leaves room for the microphone button.
 *
 * @param bottomPadding the room left under the last section (more when a bar floats over it, e.g. the quick add).
 */
@Composable
fun GroupedList(
    modifier: Modifier = Modifier,
    bottomPadding: Dp = FabClearance,
    content: LazyListScope.() -> Unit,
) {
    LazyColumn(
        modifier = modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 8.dp, bottom = bottomPadding),
        verticalArrangement = Arrangement.spacedBy(16.dp),
        content = content,
    )
}

/** A group of rows on a filled card, with an optional subheader and supporting text. */
@Composable
fun Section(
    modifier: Modifier = Modifier,
    header: String? = null,
    footer: String? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(modifier.fillMaxWidth()) {
        if (header != null) {
            Text(
                header,
                style = MaterialTheme.typography.titleSmall,
                color = MaterialTheme.colorScheme.primary,
                modifier = Modifier.padding(start = 16.dp, bottom = 8.dp),
            )
        }

        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = MaterialTheme.shapes.large,
            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceContainer),
        ) {
            Column(Modifier.fillMaxWidth(), content = content)
        }

        if (footer != null) {
            Text(
                footer,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 8.dp),
            )
        }
    }
}

/** A list item of a [Section]: leading content, a headline, supporting text and trailing content. */
@Composable
fun ListRow(
    title: String,
    modifier: Modifier = Modifier,
    subtitle: String? = null,
    subtitleColor: Color? = null,
    leading: (@Composable () -> Unit)? = null,
    trailing: (@Composable () -> Unit)? = null,
    showDivider: Boolean = false,
    onClick: (() -> Unit)? = null,
    titleContent: (@Composable () -> Unit)? = null,
) {
    Column(modifier.fillMaxWidth().let { if (onClick != null) it.clickable(onClick = onClick) else it }) {
        Row(
            modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp).padding(horizontal = 16.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            leading?.invoke()

            Column(Modifier.weight(1f)) {
                titleContent?.invoke() ?: Text(title, style = MaterialTheme.typography.bodyLarge)

                if (subtitle != null) {
                    Text(subtitle, style = MaterialTheme.typography.bodyMedium, color = subtitleColor ?: MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }

            trailing?.invoke()
        }

        if (showDivider) {
            HorizontalDivider(Modifier.padding(horizontal = 16.dp), color = MaterialTheme.colorScheme.outlineVariant)
        }
    }
}

/** The empty or failed state of a screen: an icon, a title, a description and an optional action. */
@Composable
fun ContentUnavailable(
    title: String,
    icon: ImageVector,
    modifier: Modifier = Modifier,
    description: String? = null,
    actionLabel: String? = null,
    onAction: (() -> Unit)? = null,
) {
    Box(modifier.fillMaxSize().padding(start = 32.dp, end = 32.dp, bottom = FabClearance), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Icon(icon, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(48.dp))
            Text(title, style = MaterialTheme.typography.titleLarge, textAlign = TextAlign.Center)

            if (description != null) {
                Text(description, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center)
            }

            if (actionLabel != null && onAction != null) {
                FilledTonalButton(onClick = onAction, modifier = Modifier.padding(top = 8.dp)) {
                    Text(actionLabel)
                }
            }
        }
    }
}
