# Cloud browser runtime

The migration keeps this addon's native PHP DevTools engine and bundled axe-core.
Cloudflare Browser Run supplies the browser outside Laravel Cloud's private network.
The owner approved its browser-only key and scan/report data use on October 31, 2026.
No Node packages, local sandbox changes, or weaker scanner substitute are involved.

Set `A11Y_BROWSER_DRIVER=cloudflare`, with `SCAN_CLOUDFLARE_ACCOUNT_ID` and the
private `SCAN_CLOUDFLARE_API_TOKEN` supplied through cached Laravel configuration.
The token belongs in Cloud Secrets Manager, not source, URLs, HTML, or command arguments.
The default remains local. Existing `A11Y_ENGINE` selection remains unchanged.
Missing Cloud credentials or connection failures never substitute the PHP engine.
The key is excluded from effective editable settings and scan orchestration data.

The native WebSocket client permits credentials only on the fixed account-specific
Cloudflare WSS endpoint. TLS checks both the certificate chain and host name. It
accepts no custom host, port, query, user info, or redirects. Failures lose transport
causes before reaching scan results. An explicit provider HTTP429 gets one wait of
21 seconds and one retry, matching the Free plan's one-launch-per-20-second limit.
This is not shared concurrency control. Free browser minutes are shared with the
other apps and require a capacity decision before production cutover.

Each DevTools session explicitly closes its browser at the browser protocol level.
Command deadlines cover writes and reads. A peer that stops reading cannot hold
cleanup open: the browser close command has five seconds, then the closing socket
frame has at most 100 milliseconds before the socket is released. This was checked
with a deliberately full unread socket, not just a successful remote close.

PDF printing sends the report's existing self-contained HTML with tagged-PDF and
outline flags. The existing metadata/title/language/link-description pass still runs.
A fictional report produced through the actual ReportWriter passed veraPDF 1.30.3's
PDF/UA-1 profile. That verifies this tested output, not every possible customer
report or all manual accessibility requirements. Existing historical PDFs are not
re-rendered. Public example.com scanning returned 13 coverage families through the
bundled axe engine, and native PDF output had tags, an outline, and a language.

Tests include credential/destination rejection, one retry, masked errors, failed
server-only settings, missing local Chrome with remote engine selection, and full-socket
write/close deadlines. The integration test requires explicit
`RUN_CLOUD_BROWSER_TESTS=1` and credentials in its process environment. Do not enable
it in ordinary CI against real customer pages.

The final local suite passed 352 tests with two explicit opt-in skips and 2,507
assertions. The native protocol tests and independent stalled-peer review passed.
Cloud deployment and actual Cloud feature checks remain pending. Preview workers
stay off until those checks and the broader migration are ready.

Provider protocol: https://developers.cloudflare.com/browser-run/cdp/
TLS settings: https://www.php.net/manual/en/context.ssl.php
