package app.jarvis.android.auth

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import java.security.KeyStore
import java.util.Base64
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** Encrypts the token before it reaches the disk. */
interface TokenCipher {
    fun encrypt(plainText: String): String

    /** `null` when the text cannot be read back (the key is gone, e.g. after a restore). */
    fun decrypt(cipherText: String): String?
}

/**
 * AES-256-GCM with a key kept in the Android Keystore, never exported: the
 * token is useless outside this install. The stored text is
 * `base64(iv) + ":" + base64(ciphertext)`.
 */
class KeystoreTokenCipher(private val alias: String = "jarvis.session-token") : TokenCipher {
    override fun encrypt(plainText: String): String {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, key())
        val encrypted = cipher.doFinal(plainText.toByteArray(Charsets.UTF_8))
        val encoder = Base64.getEncoder()

        return encoder.encodeToString(cipher.iv) + ":" + encoder.encodeToString(encrypted)
    }

    override fun decrypt(cipherText: String): String? = runCatching {
        val (iv, encrypted) = cipherText.split(":", limit = 2).map(Base64.getDecoder()::decode)
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(TAG_LENGTH_BITS, iv))

        String(cipher.doFinal(encrypted), Charsets.UTF_8)
    }.getOrNull()

    private fun key(): SecretKey {
        val keyStore = KeyStore.getInstance(KEYSTORE).apply { load(null) }
        (keyStore.getEntry(alias, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE)
        generator.init(
            KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build(),
        )

        return generator.generateKey()
    }

    private companion object {
        const val KEYSTORE = "AndroidKeyStore"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val TAG_LENGTH_BITS = 128
    }
}
