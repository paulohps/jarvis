#!/usr/bin/env python3
"""Renders the Jarvis brand (resources/brand/*.svg) into every icon the web and the apps ship,
and the launch screens of the web app installed on iOS (public/splash, one per screen size
listed in app/Support/Pwa/StartupImages.php — keep both lists the same).

Needs Google Chrome (it rasterizes the SVGs) and Pillow (`pip install pillow`).
Run from anywhere: `python3 scripts/generate-brand-assets.py`.
"""

import io
import pathlib
import subprocess
import tempfile

from PIL import Image

ROOT = pathlib.Path(__file__).resolve().parent.parent
BRAND = ROOT / "resources/brand"
CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
IOS_ICONS = ROOT / "apps/ios/Jarvis/Resources/Assets.xcassets/AppIcon.appiconset"
SPLASH_BACKGROUND = "#0A5BE0"  # The brand blue of the iOS launch screen.

# The screens of app/Support/Pwa/StartupImages.php: CSS width, height (points) and pixel ratio.
SCREENS = [
    (440, 956, 3), (430, 932, 3), (428, 926, 3), (414, 896, 3), (414, 896, 2), (402, 874, 3),
    (393, 852, 3), (390, 844, 3), (375, 812, 3), (375, 667, 2),
    (1024, 1366, 2), (834, 1210, 2), (834, 1194, 2), (820, 1180, 2), (810, 1080, 2), (768, 1024, 2), (744, 1133, 2),
]


def render(svg: str, size: int, background: str = "transparent") -> Image.Image:
    """Rasterizes an SVG file of `resources/brand` at `size` × `size` pixels."""
    with tempfile.TemporaryDirectory() as directory:
        page = pathlib.Path(directory) / "page.html"
        shot = pathlib.Path(directory) / "shot.png"
        page.write_text(
            f'<html><body style="margin:0;background:{background}">'
            f'<img src="{(BRAND / svg).as_uri()}" style="display:block;width:{size}px;height:{size}px">'
            "</body></html>"
        )
        subprocess.run(
            [CHROME, "--headless", "--disable-gpu", "--hide-scrollbars", "--allow-file-access-from-files",
             "--force-device-scale-factor=1", "--default-background-color=00000000",
             f"--window-size={size},{size}", f"--screenshot={shot}", page.as_uri()],
            check=True, capture_output=True,
        )

        return Image.open(io.BytesIO(shot.read_bytes())).convert("RGBA").crop((0, 0, size, size))


def splash(width: int, height: int) -> Image.Image:
    """The launch screen at `width` × `height` pixels: the mark centered on the brand blue."""
    mark = round(min(width, height) * 0.28)
    with tempfile.TemporaryDirectory() as directory:
        page = pathlib.Path(directory) / "page.html"
        shot = pathlib.Path(directory) / "shot.png"
        page.write_text(
            f'<html><body style="margin:0;width:{width}px;height:{height}px;background:{SPLASH_BACKGROUND};'
            'display:grid;place-items:center">'
            f'<img src="{(BRAND / "splash.svg").as_uri()}" style="display:block;width:{mark}px;height:{mark}px">'
            "</body></html>"
        )
        subprocess.run(
            [CHROME, "--headless", "--disable-gpu", "--hide-scrollbars", "--allow-file-access-from-files",
             "--force-device-scale-factor=1", f"--window-size={width},{height}", f"--screenshot={shot}", page.as_uri()],
            check=True, capture_output=True,
        )

        return Image.open(io.BytesIO(shot.read_bytes())).convert("RGB").crop((0, 0, width, height))


def opaque(image: Image.Image) -> Image.Image:
    """The App Store refuses an icon with an alpha channel."""
    return image.convert("RGB")


def main() -> None:
    public = ROOT / "public"

    # Web: favicons, the home screen icons and the PWA's (maskable keeps the mark in the safe zone).
    opaque(render("icon.svg", 180)).save(public / "apple-touch-icon.png")
    render("icon.svg", 192).save(public / "icon-192.png")
    render("icon.svg", 512).save(public / "icon-512.png")
    render("icon-maskable.svg", 512).save(public / "icon-maskable-512.png")
    favicon = render("favicon.svg", 256)
    favicon.save(public / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)])
    (public / "favicon.svg").write_text((BRAND / "favicon.svg").read_text())

    # The PWA: the monochrome badge Android draws in the status bar for a push,
    # and the launch screens of iOS, portrait and landscape.
    render("mark.svg", 96).save(public / "badge-96.png")
    (public / "splash").mkdir(exist_ok=True)
    for width, height, ratio in SCREENS:
        for pixel_width, pixel_height in ((width * ratio, height * ratio), (height * ratio, width * ratio)):
            splash(pixel_width, pixel_height).save(
                public / "splash" / f"launch-{pixel_width}x{pixel_height}.png", optimize=True
            )

    # iOS: the 1024 pt icon for the light, dark and tinted home screens.
    opaque(render("icon.svg", 1024)).save(IOS_ICONS / "AppIcon.png")
    render("icon-dark.svg", 1024).save(IOS_ICONS / "AppIcon-Dark.png")
    opaque(render("icon-tinted.svg", 1024, background="#000")).save(IOS_ICONS / "AppIcon-Tinted.png")


if __name__ == "__main__":
    main()
