<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Chrome;

/**
 * Chrome went quiet and the deadline passed.
 *
 * Its own type because the caller is the only one that knows what the silence
 * means. Waiting for the answer to a command, it is a browser that has stopped
 * talking and is worth throwing away. Waiting for a page to finish loading, it
 * is the page that never finished, and throwing the browser away to ask it a
 * second time buys nothing but another browser start.
 */
final class ChromeTimedOut extends ChromeProtocolError {}
