<?php
$plugin = "mirror";
$configDir = "/boot/config/plugins/$plugin";
$configFile = "$configDir/config.json";
$actionFile = "$configDir/last-action.txt";

function mirror_redirect() {
    $target = $_SERVER["HTTP_REFERER"] ?? "/Settings/Mirror";
    header("Location: $target");
    exit;
}

function mirror_write_action($message) {
    global $configDir, $actionFile;
    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    file_put_contents($actionFile, trim($message));
}

function mirror_clean_path($path) {
    $path = trim((string)$path);
    $path = preg_replace("#/+#", "/", $path);
    return rtrim($path, "/");
}

$action = $_POST["action"] ?? "status";

if ($action === "save-config") {
    $serverARoot = mirror_clean_path($_POST["server_a_root"] ?? "");
    $serverBRoot = mirror_clean_path($_POST["server_b_root"] ?? "");
    $authority = $_POST["authority"] ?? "server_a_preferred";
    $interval = max(1, min(3600, (int)($_POST["sync_interval"] ?? 10)));
    $deletePropagation = isset($_POST["delete_propagation"]);

    $errors = [];
    foreach (["Server A" => $serverARoot, "Server B" => $serverBRoot] as $label => $path) {
        if ($path === "" || strpos($path, "/mnt/user/") !== 0) {
            $errors[] = "$label path must start with /mnt/user/";
        }
    }
    if ($serverARoot !== "" && $serverARoot === $serverBRoot) {
        $errors[] = "Server A and Server B paths must be different.";
    }
    if (!in_array($authority, ["server_a_preferred", "equal_peers"], true)) {
        $authority = "server_a_preferred";
    }

    if ($errors) {
        mirror_write_action("Settings not saved:\n" . implode("\n", $errors));
        mirror_redirect();
    }

    $config = [
        "server_a" => ["name" => "server-a", "root" => $serverARoot],
        "server_b" => ["name" => "server-b", "root" => $serverBRoot],
        "state_db" => "$configDir/mirror.sqlite3",
        "trash_root" => "$configDir/trash",
        "authority" => $authority,
        "delete_propagation" => $deletePropagation,
        "sync_interval" => $interval,
    ];

    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    file_put_contents(
        $configFile,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    mirror_write_action("Settings saved.");
    mirror_redirect();
}

$allowed = ["status", "run-once", "start", "stop"];
if (!in_array($action, $allowed, true)) {
    $action = "status";
}

$cmd = "/usr/local/sbin/mirrorctl " . escapeshellarg($action) . " 2>&1";
exec($cmd, $output, $code);
$message = implode("\n", $output);
if ($code !== 0) {
    $message = "Command failed:\n" . $message;
}
mirror_write_action($message);
mirror_redirect();
