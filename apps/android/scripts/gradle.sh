#!/usr/bin/env bash
# Runs the Gradle wrapper of the Jarvis Android app.
#
#   apps/android/scripts/gradle.sh test                # the JUnit suites
#   apps/android/scripts/gradle.sh assembleRelease     # the signed APK
#
# With a JDK and an Android SDK on the machine (Android Studio), it is just
# `./gradlew`. Without them, it runs in Docker (`docker/Dockerfile`: JDK 21 +
# Android SDK 37), keeping the Gradle cache in the `jarvis-android-gradle` volume.
# The incremental Kotlin caches do not survive the bind mount, so they are off there.
set -euo pipefail

cd "$(dirname "$0")/.."

has_local_toolchain() {
    { [[ -n "${ANDROID_HOME:-}" ]] || [[ -f local.properties ]]; } && java -version >/dev/null 2>&1
}

if [[ "${JARVIS_ANDROID_DOCKER:-}" != "1" ]] && has_local_toolchain; then
    exec ./gradlew "$@"
fi

IMAGE="jarvis-android-build"

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    docker build --platform linux/amd64 -t "$IMAGE" docker
fi

TTY_FLAGS=()
[[ -t 1 ]] && TTY_FLAGS=(-t)

exec docker run --rm "${TTY_FLAGS[@]}" \
    -v jarvis-android-gradle:/gradle \
    -v "$PWD":/workspace \
    -w /workspace \
    "$IMAGE" ./gradlew --console=plain -Pkotlin.incremental=false "$@"
