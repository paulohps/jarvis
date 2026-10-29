package app.jarvis.android.app

import android.content.Context
import android.provider.Settings
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.datastore.preferences.preferencesDataStoreFile
import app.jarvis.android.auth.AuthService
import app.jarvis.android.auth.DataStoreTokenStore
import app.jarvis.android.auth.KeystoreTokenCipher
import app.jarvis.android.i18n.LanguagePreference
import app.jarvis.android.i18n.SystemAppLocales
import app.jarvis.android.navigation.DeepLinkRouter
import app.jarvis.android.network.ApiClient
import app.jarvis.android.notifications.NotificationsStore
import app.jarvis.android.offline.DataStoreMutationStore
import app.jarvis.android.offline.OfflineQueue
import app.jarvis.android.offline.ShoppingSync
import app.jarvis.android.offline.WorkManagerSyncScheduler
import app.jarvis.android.push.PushCoordinator
import app.jarvis.android.realtime.RealtimeCoordinator
import app.jarvis.android.realtime.WhatsappLive
import app.jarvis.android.ui.schedule.WallClock
import app.jarvis.android.voice.AndroidMicrophonePermission
import app.jarvis.android.voice.AndroidSpeechInput
import app.jarvis.android.voice.AndroidVoiceSpeaker
import app.jarvis.android.voice.GeminiNanoVoiceModel
import app.jarvis.android.voice.SharedPreferencesInterpreterStore
import app.jarvis.android.voice.ResourceVoiceStrings
import app.jarvis.android.voice.VoiceController
import app.jarvis.android.voice.VoiceInterpreter
import app.jarvis.android.voice.VoicePermissions
import app.jarvis.android.voice.VoiceService
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.first

/** The objects of the app, made once per process (`JarvisApplication`). */
class AppContainer(context: Context, val configuration: AppConfiguration = AppConfiguration.current) {
    private val appContext = context.applicationContext

    /** Lives as long as the process. */
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)

    private val ioScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    val tokenStore = DataStoreTokenStore(
        PreferenceDataStoreFactory.create(scope = ioScope) { appContext.preferencesDataStoreFile("session") },
        KeystoreTokenCipher(),
    )

    private val cacheStore = PreferenceDataStoreFactory.create(scope = ioScope) { appContext.preferencesDataStoreFile("cache") }

    val languagePreference = LanguagePreference(SystemAppLocales(appContext))

    val client = ApiClient(
        baseUrl = configuration.apiBaseUrl,
        tokenStore = tokenStore,
        acceptLanguage = { languagePreference.current.languageTag },
    )

    /** The name the user gave the phone (Settings → About), as the device list shows it. */
    val deviceName: String by lazy {
        Settings.Global.getString(appContext.contentResolver, Settings.Global.DEVICE_NAME)?.takeIf { it.isNotBlank() }
            ?: "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL}"
    }

    val auth = AuthService(client) { deviceName }

    val model = AppModel(scope, client, auth, languagePreference, UserCache(cacheStore), DeepLinkRouter(configuration.apiBaseUrl))

    val notifications = NotificationsStore(client, scope)

    val push = PushCoordinator(appContext, client, { deviceName }, scope)

    /** The WhatsApp chats in realtime: updates, presence and the in-app banner. */
    val whatsapp: WhatsappLive = WhatsappLive(client, scope, { realtime.isConnected })

    val realtime: RealtimeCoordinator = RealtimeCoordinator(appContext, configuration, client, scope, notifications::didReceive) { name, data -> whatsapp.receive(name, data) }

    /** The offline queue of shopping item changes (US-8.2), sent by WorkManager when the network is back. */
    val shoppingSync = ShoppingSync(
        OfflineQueue(client, DataStoreMutationStore(PreferenceDataStoreFactory.create(scope = ioScope) { appContext.preferencesDataStoreFile("offline") })),
        WorkManagerSyncScheduler(appContext),
    )

    private val voiceService = VoiceService(client)

    /** The microphone button and the App Actions (US-10.1 to US-10.3). */
    val voice = VoiceController(
        scope = scope,
        currentLanguage = { model.language },
        awaitSignedIn = { model.state.first { it !is SessionState.Launching } is SessionState.SignedIn },
        currentZone = { WallClock.zone(model.user?.timezone) },
        service = voiceService,
        interpreter = VoiceInterpreter(voiceService, GeminiNanoVoiceModel()),
        preferences = SharedPreferencesInterpreterStore(appContext),
        permissions = VoicePermissions(AndroidMicrophonePermission(appContext)),
        speech = AndroidSpeechInput(appContext),
        speaker = AndroidVoiceSpeaker(appContext),
        strings = ResourceVoiceStrings(appContext),
    )

    init {
        model.addListener(voice)
        model.addListener(notifications)
        model.addListener(push)
        model.addListener(realtime)
        model.addListener(whatsapp)
        model.addListener(shoppingSync)
    }
}
