# Build environment of the Jarvis Android app: JDK + Android SDK, for hosts
# without Android Studio (`scripts/gradle.sh` uses it). linux/amd64 because the
# SDK build tools (aapt2) ship only for x86_64 on Linux, so the image is built
# with `--platform linux/amd64`.
FROM eclipse-temurin:21-jdk

ARG CMDLINE_TOOLS=16111833
ARG PLATFORM=android-37.0
ARG BUILD_TOOLS=37.0.0
# The default build tools of AGP 9.4, so Gradle does not download them on each run.
ARG AGP_BUILD_TOOLS=36.0.0

ENV ANDROID_HOME=/opt/android-sdk \
    ANDROID_SDK_ROOT=/opt/android-sdk \
    GRADLE_USER_HOME=/gradle
ENV PATH=${ANDROID_HOME}/cmdline-tools/latest/bin:${ANDROID_HOME}/platform-tools:${PATH}

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip curl git \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p ${ANDROID_HOME}/cmdline-tools \
    && curl -fsSL -o /tmp/tools.zip "https://dl.google.com/android/repository/commandlinetools-linux-${CMDLINE_TOOLS}_latest.zip" \
    && unzip -q /tmp/tools.zip -d /tmp/tools \
    && mv /tmp/tools/cmdline-tools ${ANDROID_HOME}/cmdline-tools/latest \
    && rm -rf /tmp/tools /tmp/tools.zip

RUN yes | sdkmanager --licenses >/dev/null \
    && sdkmanager --install "platforms;${PLATFORM}" "build-tools;${BUILD_TOOLS}" "build-tools;${AGP_BUILD_TOOLS}" "platform-tools" >/dev/null

WORKDIR /workspace
