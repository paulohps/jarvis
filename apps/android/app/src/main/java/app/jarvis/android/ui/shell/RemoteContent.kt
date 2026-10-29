package app.jarvis.android.ui.shell

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
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
import androidx.compose.ui.res.stringResource
import app.jarvis.android.R
import app.jarvis.android.network.ApiException
import app.jarvis.android.ui.components.ContentUnavailable
import kotlinx.coroutines.launch

/** What a module screen got from the API. */
sealed interface LoadState<out T> {
    data object Loading : LoadState<Nothing>

    data class Loaded<T>(val value: T) : LoadState<T>

    /** 403: the module was switched off (US-11.1). */
    data object Forbidden : LoadState<Nothing>

    /** The message to show, `null` for the generic one. */
    data class Failed(val message: String?, val isOffline: Boolean) : LoadState<Nothing>

    companion object {
        /** Runs [load] and tells what to show. A 401 keeps loading: the shell is already at the login. */
        suspend fun <T> of(load: suspend () -> T): LoadState<T> = try {
            Loaded(load())
        } catch (_: ApiException.Forbidden) {
            Forbidden
        } catch (_: ApiException.Unauthenticated) {
            Loading
        } catch (_: ApiException.Transport) {
            Failed(null, isOffline = true)
        } catch (exception: ApiException) {
            Failed(exception.displayMessage, isOffline = false)
        }
    }
}

/**
 * Loads a module screen from the API and shows it, with pull to refresh, or
 * "No access" when the server answers 403 (the module was switched off; the
 * client already asked the shell to read `/me` again, so the tab goes away).
 *
 * @param key loads again when it changes (a filter).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun <T> RemoteContent(
    key: Any?,
    load: suspend () -> T,
    onRetryAccess: suspend () -> Unit,
    content: @Composable (value: T, reload: () -> Unit) -> Unit,
) {
    var state by remember(key) { mutableStateOf<LoadState<T>>(LoadState.Loading) }
    var isRefreshing by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    val reload: () -> Unit = {
        scope.launch {
            isRefreshing = true
            state = LoadState.of(load)
            isRefreshing = false
        }
    }

    LaunchedEffect(key) {
        state = LoadState.of(load)
    }

    PullToRefreshBox(isRefreshing = isRefreshing, onRefresh = reload, modifier = Modifier.fillMaxSize()) {
        when (val current = state) {
            LoadState.Loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }

            is LoadState.Loaded -> content(current.value, reload)
            LoadState.Forbidden -> NoAccessScreen(onRetry = { scope.launch { onRetryAccess() } })
            is LoadState.Failed -> ContentUnavailable(
                title = current.message ?: stringResource(if (current.isOffline) R.string.error_unreachable else R.string.error_generic),
                icon = Icons.Filled.WifiOff,
                actionLabel = stringResource(R.string.try_again),
                onAction = reload,
            )
        }
    }
}
