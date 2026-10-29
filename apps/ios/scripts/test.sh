#!/usr/bin/env bash
# Runs the XCTest suites of the Jarvis iOS app (the JarvisKit package tests,
# through the shared `Jarvis` scheme) on an iOS simulator.
#
#   apps/ios/scripts/test.sh                 # first available iPhone simulator
#   SIMULATOR="iPhone 17 Pro" apps/ios/scripts/test.sh
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ -z "${SIMULATOR:-}" ]]; then
    SIMULATOR=$(xcrun simctl list devices available --json \
        | python3 -c 'import json,sys; d=json.load(sys.stdin)["devices"]; print(next(x["name"] for k in sorted(d, reverse=True) if "iOS" in k for x in d[k] if x["name"].startswith("iPhone")))')
fi

echo "Testing on: ${SIMULATOR}"

xcodebuild test \
    -project Jarvis.xcodeproj \
    -scheme Jarvis \
    -destination "platform=iOS Simulator,name=${SIMULATOR}" \
    -skipPackagePluginValidation \
    | { command -v xcbeautify >/dev/null && xcbeautify || cat; }
