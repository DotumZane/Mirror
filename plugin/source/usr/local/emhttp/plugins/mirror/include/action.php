<?php
$allowed = ["status", "run-once", "start", "stop"];
$action = $_POST["action"] ?? "status";
if (!in_array($action, $allowed, true)) {
    $action = "status";
}

$cmd = "/usr/local/sbin/mirrorctl " . escapeshellarg($action) . " 2>&1";
exec($cmd, $output, $code);

header("Content-Type: text/plain");
echo implode("\n", $output);
echo "\n";
exit($code);
