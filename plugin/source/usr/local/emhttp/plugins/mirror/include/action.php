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

function mirror_current_shares() {
    $shares = [];
    foreach (glob("/mnt/user/*", GLOB_ONLYDIR) ?: [] as $path) {
        $name = basename($path);
        if ($name === "" || $name[0] === ".") {
            continue;
        }
        $shares[$name] = true;
    }
    return $shares;
}

$action = $_POST["action"] ?? "status";

if ($action === "save-config") {
    $serverAShare = trim((string)($_POST["server_a_share"] ?? ""));
    $serverBShare = trim((string)($_POST["server_b_share"] ?? ""));
    $authority = $_POST["authority"] ?? "server_a_preferred";
    $interval = max(1, min(3600, (int)($_POST["sync_interval"] ?? 10)));
    $deletePropagation = isset($_POST["delete_propagation"]);
    $shares = mirror_current_shares();

    $errors = [];
    foreach (["Server A" => $serverAShare, "Server B" => $serverBShare] as $label => $share) {
        if ($share === "" || !isset($shares[$share])) {
            $errors[] = "$label share must be selected from current /mnt/user shares.";
        }
    }
    if ($serverAShare !== "" && $serverAShare === $serverBShare) {
        $errors[] = "Server A and Server B shares must be different.";
    }
    if (!in_array($authority, ["server_a_preferred", "equal_peers"], true)) {
        $authority = "server_a_preferred";
    }

    if ($errors) {
        mirror_write_action("Settings not saved:\n" . implode("\n", $errors));
        mirror_redirect();
    }

    $serverARoot = "/mnt/user/" . $serverAShare;
    $serverBRoot = "/mnt/user/" . $serverBShare;

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
