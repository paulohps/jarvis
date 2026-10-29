# kotlinx.serialization: keep the generated serializers of the API models.
-keepattributes *Annotation*, InnerClasses, Signature, Exceptions
-keepclassmembers @kotlinx.serialization.Serializable class app.jarvis.android.** {
    *** Companion;
    kotlinx.serialization.KSerializer serializer(...);
}
-keep,includedescriptorclasses class app.jarvis.android.**$$serializer { *; }

# Retrofit: the API interface is read by reflection.
-keep,allowobfuscation interface app.jarvis.android.network.JarvisApi
-keep,allowobfuscation,allowshrinking class kotlin.coroutines.Continuation

# Pusher client (Java-WebSocket + Gson).
-keep class com.pusher.** { *; }
-keep class org.java_websocket.** { *; }
-dontwarn org.slf4j.**
