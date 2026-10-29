import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
}

/** The machine's settings (`jarvis.properties`, git-ignored; see `jarvis.properties.example`). */
val jarvisProperties = Properties().apply {
    val file = rootProject.file("jarvis.properties")

    if (file.exists()) {
        file.inputStream().use(::load)
    }
}

fun jarvis(key: String, default: String = ""): String =
    (jarvisProperties.getProperty("jarvis.$key") ?: providers.environmentVariable("JARVIS_${key.uppercase()}").orNull ?: default).trim()

fun String.quoted(): String = "\"" + replace("\\", "\\\\").replace("\"", "\\\"") + "\""

// Firebase (push) only when the project's google-services.json is in place: without it the app runs, without push.
if (file("google-services.json").exists()) {
    apply(plugin = libs.plugins.google.services.get().pluginId)
}

android {
    namespace = "app.jarvis.android"
    compileSdk = 37

    defaultConfig {
        applicationId = "jarvis.campeonato.app"
        minSdk = 34
        targetSdk = 37
        versionCode = 2
        versionName = "1.1.0"

        // Defaults to production; jarvis.properties points it at Sail for development.
        buildConfigField("String", "API_BASE_URL", jarvis("apiBaseUrl", "https://jarvis.campeonato.app").quoted())
        buildConfigField("String", "REVERB_HOST", jarvis("reverbHost", "ws-jarvis.campeonato.app").quoted())
        buildConfigField("int", "REVERB_PORT", jarvis("reverbPort", "443"))
        buildConfigField("boolean", "REVERB_USE_TLS", jarvis("reverbUseTls", "true"))
        buildConfigField("String", "REVERB_APP_KEY", jarvis("reverbAppKey", "9dc984ac50a5da563499").quoted())
        buildConfigField("String", "GOOGLE_SERVER_CLIENT_ID", jarvis("googleServerClientId").quoted())

        // Plain http only when the configured server is plain http (a server on the LAN).
        manifestPlaceholders["usesCleartextTraffic"] =
            (jarvis("apiBaseUrl", "https://jarvis.campeonato.app").startsWith("http://") || jarvis("reverbUseTls", "true") != "true").toString()
    }

    signingConfigs {
        create("release") {
            val storePath = jarvis("signing.storeFile")

            if (storePath.isNotEmpty()) {
                storeFile = rootProject.file(storePath)
                storePassword = jarvis("signing.storePassword")
                keyAlias = jarvis("signing.keyAlias")
                keyPassword = jarvis("signing.keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")

            if (jarvis("signing.storeFile").isNotEmpty()) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    androidResources {
        localeFilters += listOf("en", "pt-rBR")
    }

    testOptions {
        unitTests.isReturnDefaultValues = true
    }
}

kotlin {
    compilerOptions {
        optIn.add("kotlinx.coroutines.ExperimentalCoroutinesApi")
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.process)

    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.material.icons.extended)
    debugImplementation(libs.androidx.compose.ui.tooling)

    implementation(libs.androidx.datastore.preferences)
    implementation(libs.androidx.work.runtime.ktx)
    implementation(libs.androidx.credentials)
    implementation(libs.androidx.credentials.play.services.auth)
    implementation(libs.googleid)

    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)

    implementation(libs.kotlinx.serialization.json)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.okhttp)
    implementation(libs.retrofit)
    implementation(libs.retrofit.kotlinx.serialization)
    implementation(libs.pusher)
    implementation(libs.mlkit.genai.prompt)

    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.coroutines.test)
}
