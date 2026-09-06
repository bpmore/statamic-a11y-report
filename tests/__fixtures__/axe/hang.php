<?php

// A page that arrives and never finishes. The document is served at once; the
// image points at a socket that accepts the connection and answers nothing, so
// the load event never fires. There is no other way to get a page that hangs
// out of a single-threaded test server: stalling the response would stall
// every other fixture with it.
$port = (int) ($_GET['port'] ?? 0);

?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Never finishes</title></head>
<body>
<h1>Never finishes</h1>
<img src="http://127.0.0.1:<?= $port ?>/never-arrives.jpg" alt="A picture that never arrives">
</body>
</html>
