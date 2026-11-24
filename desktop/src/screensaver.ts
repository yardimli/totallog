import type { Row } from "./logging-model";
export const screensaverStyles = [
    "bouncing-ball",
    "fade-out",
    "fish",
    "flying-toasters",
    "globe",
    "hard-rain",
    "logo",
    "messages",
    "messages2",
    "rainstorm",
    "spotlight",
    "starry-night",
    "warp",
];
let settings: Row = {},
    lastActivity = Date.now(),
    overlay: HTMLDivElement | null = null;
export function updateScreensaver(value: Row) {
    settings = value;
}
export function stopScreensaver() {
    overlay?.remove();
    overlay = null;
    lastActivity = Date.now();
}
export function startScreensaver() {
    if (overlay) return;
    const style = screensaverStyles.includes(settings.screensaver_style)
        ? settings.screensaver_style
        : "flying-toasters";
    overlay = document.createElement("div");
    overlay.className = "desktop-screensaver";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-label", "Screensaver. Press any key to return.");
    const frame = document.createElement("iframe");
    frame.title = "Screensaver";
    frame.tabIndex = -1;
    frame.src =
        style === "starry-night"
            ? "/starry-night/index.html"
            : `/after-dark/all/${style}.html`;
    frame.addEventListener("load", () => {
        const doc = frame.contentDocument;
        if (!doc) return;
        doc.documentElement.style.setProperty(
            "--screensaver-speed",
            String(settings.screensaver_speed || 1),
        );
        doc.querySelectorAll(".message").forEach(
            (el) =>
                (el.textContent =
                    settings.screensaver_message || "OUT TO LUNCH"),
        );
        if (style === "logo" && settings.logo)
            doc.querySelectorAll("body img").forEach(
                (el) => ((el as HTMLImageElement).src = settings.logo),
            );
        frame.contentWindow?.postMessage(
            {
                type: "totallog-screensaver-settings",
                speed: settings.screensaver_speed || 1,
            },
            window.location.origin,
        );
    });
    overlay.append(frame);
    document.body.append(overlay);
    lastActivity = Date.now();
}
for (const event of ["pointerdown", "keydown", "wheel", "pointermove"])
    document.addEventListener(
        event,
        () => {
            if (Date.now() - lastActivity > 500) stopScreensaver();
            else lastActivity = Date.now();
        },
        { passive: true },
    );
window.setInterval(() => {
    if (!document.hasFocus() || document.hidden) {
        stopScreensaver();
        return;
    }
    if (
        settings.screensaver_enabled &&
        Date.now() - lastActivity >=
            Math.max(1, settings.screensaver_wait_minutes || 10) * 60000
    )
        startScreensaver();
}, 5000);
