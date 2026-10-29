package app.jarvis.android.navigation

import app.jarvis.android.network.WhatsappConversation
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

/** A screen a tab can push over its root. */
sealed interface Destination {
    data class ShoppingList(val id: String) : Destination

    data class Reminder(val id: String) : Destination

    /**
     * A WhatsApp chat; [title] shows while it loads. [chat] is the list's row
     * (its picture and kind for the header); without it (a push) the screen
     * looks the chat up.
     */
    data class WhatsappChat(val id: String, val title: String, val chat: WhatsappConversation? = null) : Destination

    /** Settings: the user's own color for one of their workspaces, and making it their default. */
    data class WorkspacePreferences(val id: String) : Destination

    /** Settings → Administration (super admin only): the workspaces. */
    data object AdminWorkspaces : Destination

    /** A workspace and its members, for the super admin. */
    data class AdminWorkspace(val id: String) : Destination

    /** The users, for the super admin. */
    data object AdminUsers : Destination

    /** The invitations, for the super admin. */
    data object AdminInvitations : Destination
}

/** Which tab is shown and what each tab has pushed. */
data class RouterState(
    val selectedTab: AppTab = AppTab.Notifications,
    val stacks: Map<AppTab, List<Destination>> = emptyMap(),
) {
    fun stack(tab: AppTab): List<Destination> = stacks[tab].orEmpty()
}

class Router {
    private val mutableState = MutableStateFlow(RouterState())
    val state: StateFlow<RouterState> = mutableState.asStateFlow()

    fun select(tab: AppTab) = mutableState.update { it.copy(selectedTab = tab) }

    /** Tapping the shown tab again goes back to its root, as on iOS. */
    fun reselect(tab: AppTab) = mutableState.update { it.copy(stacks = it.stacks - tab) }

    fun push(tab: AppTab, destination: Destination) =
        mutableState.update { it.copy(stacks = it.stacks + (tab to it.stack(tab) + destination)) }

    /** @return `false` when the tab was already at its root. */
    fun pop(tab: AppTab): Boolean {
        val stack = state.value.stack(tab)

        if (stack.isEmpty()) {
            return false
        }

        mutableState.update { it.copy(stacks = it.stacks + (tab to stack.dropLast(1))) }

        return true
    }

    /** Keeps the selection valid after the tabs changed (a module switched off). */
    fun keepSelection(tabs: List<AppTab>) = mutableState.update { state ->
        state.copy(
            selectedTab = NavigationBuilder.selection(state.selectedTab, tabs),
            stacks = state.stacks.filterKeys { it in tabs },
        )
    }

    /**
     * Shows a deep link's tab and screen (US-5.2).
     *
     * @return `false` when the user has no such tab (a module switched off) or it is a web page.
     */
    fun open(route: AppRoute, tabs: List<AppTab>): Boolean {
        val tab = route.tab?.takeIf { it in tabs } ?: return false

        val stack = when (route) {
            is AppRoute.Reminder -> listOf(Destination.Reminder(route.id))
            is AppRoute.ShoppingList -> listOf(Destination.ShoppingList(route.id))
            // Its name comes with the chat's first page.
            is AppRoute.WhatsappChat -> listOf(Destination.WhatsappChat(route.id, title = ""))
            is AppRoute.Tab, is AppRoute.Web -> emptyList()
        }

        mutableState.update { it.copy(selectedTab = tab, stacks = it.stacks + (tab to stack)) }

        return true
    }

    /** A new session starts at its first tab. */
    fun reset(tabs: List<AppTab>) {
        mutableState.value = RouterState(selectedTab = NavigationBuilder.selection(null, tabs))
    }
}
