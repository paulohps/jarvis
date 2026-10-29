package app.jarvis.android.ui.shell

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.consumeWindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Chat
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material.icons.outlined.ShoppingCart
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.jarvis.android.R
import app.jarvis.android.app.AppContainer
import app.jarvis.android.app.Features
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.network.User
import app.jarvis.android.ui.admin.AdminInvitationsScreen
import app.jarvis.android.ui.admin.AdminUsersScreen
import app.jarvis.android.ui.admin.AdminWorkspaceScreen
import app.jarvis.android.ui.admin.AdminWorkspacesScreen
import app.jarvis.android.ui.notifications.NotificationsScreen
import app.jarvis.android.ui.reminders.ReminderDetailScreen
import app.jarvis.android.ui.reminders.RemindersHomeScreen
import app.jarvis.android.ui.settings.SettingsScreen
import app.jarvis.android.ui.settings.WorkspacePreferencesScreen
import app.jarvis.android.ui.shopping.ShoppingHomeScreen
import app.jarvis.android.ui.shopping.ShoppingListScreen
import app.jarvis.android.ui.voice.VoiceButton
import app.jarvis.android.ui.whatsapp.WhatsAppHomeScreen
import app.jarvis.android.ui.whatsapp.WhatsappBannerHost
import app.jarvis.android.ui.whatsapp.WhatsappChatScreen

/**
 * The tabs of the signed-in user (US-11.1): the mobile modules they have, then
 * Notifications (with the unread badge) and Settings, on a Material
 * navigation bar, with the microphone as the floating action button of each
 * tab (US-10.1) while "Ask Jarvis" is on ([Features.ASK_JARVIS]). A WhatsApp
 * chat takes the whole screen, without the bar, as in WhatsApp.
 * There is no invoices or admin tab: the super admin's area is in Settings.
 * A module switched off goes away on the next `/me` (a refresh, the
 * foreground, a 403).
 */
@Composable
fun MainShell(container: AppContainer, user: User, unreadNotifications: Int = 0) {
    val model = container.model
    val tabs by model.tabs.collectAsStateWithLifecycle()
    val routerState by model.router.state.collectAsStateWithLifecycle()
    val selected = routerState.selectedTab
    val stack = routerState.stack(selected)

    BackHandler(enabled = stack.isNotEmpty()) {
        model.router.pop(selected)
    }

    val isInChat = stack.lastOrNull() is Destination.WhatsappChat

    Box(Modifier.fillMaxSize()) {
        Scaffold(
            bottomBar = {
                if (!isInChat) {
                    TabBar(
                        tabs = tabs,
                        selected = selected,
                        unreadNotifications = unreadNotifications,
                        onSelect = { tab ->
                            if (tab == selected) {
                                model.router.reselect(tab)
                            } else {
                                model.router.select(tab)
                            }
                        },
                    )
                }
            },
            // Only on a tab's first page: a pushed page has its own actions at the bottom (the quick add).
            floatingActionButton = {
                if (Features.ASK_JARVIS && stack.isEmpty()) {
                    VoiceButton(container.voice)
                }
            },
            contentWindowInsets = WindowInsets(0),
        ) { padding ->
            AnimatedContent(
                targetState = selected to stack.lastOrNull(),
                transitionSpec = { fadeIn() togetherWith fadeOut() },
                label = "tab",
                modifier = Modifier.fillMaxSize().padding(padding).consumeWindowInsets(padding),
            ) { (tab, destination) ->
                val onBack: () -> Unit = { model.router.pop(tab) }

                when (destination) {
                    is Destination.ShoppingList -> ShoppingListScreen(container, destination.id, onBack)
                    is Destination.Reminder -> ReminderDetailScreen(model, destination.id, onBack)
                    is Destination.WhatsappChat -> WhatsappChatScreen(model, container.whatsapp, destination.id, destination.title, destination.chat, onBack)
                    is Destination.WorkspacePreferences -> WorkspacePreferencesScreen(model, destination.id, onBack)
                    Destination.AdminWorkspaces -> AdminWorkspacesScreen(model, onBack)
                    is Destination.AdminWorkspace -> AdminWorkspaceScreen(model, destination.id, onBack)
                    Destination.AdminUsers -> AdminUsersScreen(model, onBack)
                    Destination.AdminInvitations -> AdminInvitationsScreen(model, onBack)
                    null -> when (tab) {
                        AppTab.Shopping -> ShoppingHomeScreen(model)
                        AppTab.Reminders -> RemindersHomeScreen(model)
                        AppTab.WhatsApp -> WhatsAppHomeScreen(model, container.whatsapp)
                        AppTab.Notifications -> NotificationsScreen(container)
                        AppTab.Settings -> SettingsScreen(container, user)
                    }
                }
            }
        }

        // A WhatsApp message to a chat not on screen, over every tab.
        WhatsappBannerHost(model, container.whatsapp, Modifier.align(Alignment.TopCenter))
    }
}

@Composable
private fun TabBar(
    tabs: List<AppTab>,
    selected: AppTab,
    unreadNotifications: Int,
    onSelect: (AppTab) -> Unit,
) {
    NavigationBar {
        tabs.forEach { tab ->
            NavigationBarItem(
                selected = tab == selected,
                onClick = { onSelect(tab) },
                icon = {
                    if (tab == AppTab.Notifications && unreadNotifications > 0) {
                        BadgedBox(badge = { Badge { Text(if (unreadNotifications > 99) "99+" else unreadNotifications.toString()) } }) {
                            Icon(tab.icon, contentDescription = null)
                        }
                    } else {
                        Icon(tab.icon, contentDescription = null)
                    }
                },
                label = { Text(stringResource(tab.title), maxLines = 1) },
                modifier = Modifier.testTag("tab.${tab.route}"),
            )
        }
    }
}

val AppTab.title: Int
    get() = when (this) {
        AppTab.Shopping -> R.string.tab_shopping
        AppTab.Reminders -> R.string.tab_reminders
        AppTab.WhatsApp -> R.string.tab_whatsapp
        AppTab.Notifications -> R.string.tab_notifications
        AppTab.Settings -> R.string.tab_settings
    }

val AppTab.icon: ImageVector
    get() = when (this) {
        AppTab.Shopping -> Icons.Outlined.ShoppingCart
        AppTab.Reminders -> Icons.Outlined.CalendarMonth
        AppTab.WhatsApp -> Icons.AutoMirrored.Outlined.Chat
        AppTab.Notifications -> Icons.Outlined.Notifications
        AppTab.Settings -> Icons.Outlined.Settings
    }
