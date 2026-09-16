<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Chrome;

/**
 * Chrome was started and exited before it had a port to be driven on.
 *
 * Told apart from every other way a browser can fail because it is the one
 * that has a second thing worth trying: Chrome refuses to run its sandbox as
 * root, and cannot in a container that will not let it make namespaces, and
 * in both cases it says so and exits at once. A browser that started, was
 * attached to, and then died is a different failure with a different cure.
 */
final class ChromeExitedAtStart extends ChromeProtocolError {}
