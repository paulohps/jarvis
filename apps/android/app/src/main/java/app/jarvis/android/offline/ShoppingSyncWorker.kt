package app.jarvis.android.offline

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import app.jarvis.android.JarvisApplication
import java.util.concurrent.TimeUnit

/**
 * Sends the offline queue once the phone is online again (US-8.2), even if the
 * app was closed meanwhile: WorkManager runs it when its network constraint is
 * met, and again with backoff while the server cannot take the changes yet.
 */
class ShoppingSyncWorker(context: Context, parameters: WorkerParameters) : CoroutineWorker(context, parameters) {
    override suspend fun doWork(): Result {
        val application = applicationContext as JarvisApplication
        application.sessionLoaded.join()

        val container = application.container

        if (container.tokenStore.token() == null) {
            return Result.success()
        }

        return if (container.shoppingSync.flush()) Result.retry() else Result.success()
    }
}

/** The [SyncScheduler] of the app: one unique work, waiting for any network. */
class WorkManagerSyncScheduler(private val context: Context) : SyncScheduler {
    override fun schedule() {
        val request = OneTimeWorkRequestBuilder<ShoppingSyncWorker>()
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.SECONDS)
            .build()

        // A work already waiting for the network sends the changes made meanwhile too.
        WorkManager.getInstance(context).enqueueUniqueWork(WORK_NAME, ExistingWorkPolicy.KEEP, request)
    }

    override fun cancel() {
        WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
    }

    private companion object {
        const val WORK_NAME = "shopping-sync"
    }
}
