package app.jarvis.android

import android.app.Application
import androidx.lifecycle.DefaultLifecycleObserver
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.ProcessLifecycleOwner
import app.jarvis.android.app.AppContainer
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

class JarvisApplication : Application() {
    lateinit var container: AppContainer
        private set

    /** Done once the saved token is read: the background work (the offline queue) waits for it. */
    lateinit var sessionLoaded: Job
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
        sessionLoaded = container.scope.launch { container.tokenStore.load() }

        val started: Job = container.scope.launch {
            sessionLoaded.join()
            container.model.start()
        }

        // Back in the foreground: `/me` again and what the shell keeps alive (realtime, push).
        ProcessLifecycleOwner.get().lifecycle.addObserver(object : DefaultLifecycleObserver {
            private var isFirstStart = true

            override fun onStart(owner: LifecycleOwner) {
                if (isFirstStart) {
                    isFirstStart = false

                    return
                }

                container.scope.launch {
                    started.join()
                    container.model.didBecomeActive()
                }
            }

            override fun onStop(owner: LifecycleOwner) {
                container.scope.launch { container.model.didEnterBackground() }
            }
        })
    }
}
