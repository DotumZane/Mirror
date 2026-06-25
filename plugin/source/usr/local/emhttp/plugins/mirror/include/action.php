<?php
$plugin = "mirror";
$configDir = "/boot/config/plugins/$plugin";
$configFile = "$configDir/config.json";
$actionFile = "$configDir/last-action.txt";
$sshDir = "$configDir/ssh";
$keyFile = "$sshDir/mirror_ed25519";
$pidFile = "/var/run/$plugin.pid";
$rootSshDir = "/root/.ssh";
$authorizedKeysFile = "$rootSshDir/authorized_keys";

function mirror_daemon_running() {
    global $pidFile;
    if (!is_file($pidFile)) {
        return false;
    }
    $pid = (int)trim((string)file_get_contents($pidFile));
    return $pid > 0 && posix_kill($pid, 0);
}

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

function mirror_existing_config() {
    global $configFile;
    $default = [
        "server_b" => [
            "type" => "local",
            "root" => "/mnt/user/mirror-test-b",
            "share" => "mirror-test-b",
            "host" => "",
            "user" => "root",
            "port" => 22,
        ],
    ];
    if (!is_file($configFile)) {
        return $default;
    }
    $decoded = json_decode((string)file_get_contents($configFile), true);
    return is_array($decoded) ? array_replace_recursive($default, $decoded) : $default;
}

$action = $_POST["action"] ?? "status";

if ($action === "save-config") {
    $wasRunning = mirror_daemon_running();
    $existingConfig = mirror_existing_config();
    $serverAShare = trim((string)($_POST["server_a_share"] ?? ""));
    $mirrorMode = trim((string)($_POST["mirror_mode"] ?? ""));
    $serverBType = $mirrorMode !== "" ? $mirrorMode : trim((string)($_POST["server_b_type"] ?? "local"));
    $serverBShare = trim((string)($_POST["server_b_share"] ?? ""));
    $remoteShare = trim((string)($_POST["remote_share"] ?? ""));
    $peerHost = trim((string)($_POST["peer_host"] ?? ""));
    $peerUser = trim((string)($_POST["peer_user"] ?? "root"));
    $peerPort = max(1, min(65535, (int)($_POST["peer_port"] ?? 22)));
    $authority = $_POST["authority"] ?? "server_a_preferred";
    $deleteBehavior = $_POST["delete_behavior"] ?? "restore_missing";
    $interval = max(1, min(3600, (int)($_POST["sync_interval"] ?? 10)));
    if (!in_array($deleteBehavior, ["restore_missing", "mirror_deletes"], true)) {
        $deleteBehavior = "restore_missing";
    }
    $deletePropagation = $deleteBehavior === "mirror_deletes";
    $shares = mirror_current_shares();

    $errors = [];
    if (!in_array($serverBType, ["local", "remote"], true)) {
        $serverBType = "local";
    }
    if ($serverAShare === "" || !isset($shares[$serverAShare])) {
        $errors[] = "Server A share must be selected from current /mnt/user shares.";
    }
    if ($serverBType === "local") {
        if ($serverBShare === "" || !isset($shares[$serverBShare])) {
            $errors[] = "Server B share must be selected from current /mnt/user shares.";
        }
        if ($serverAShare !== "" && $serverAShare === $serverBShare) {
            $errors[] = "Server A and Server B shares must be different.";
        }
        $serverBRoot = "/mnt/user/" . $serverBShare;
        $serverBConfiguredShare = $serverBShare;
    } else {
        if ($remoteShare !== "" && preg_match("#[\\x00/]+#", $remoteShare)) {
            $errors[] = "Remote peer share name must be a single share name, not a path.";
        }
        if ($remoteShare === "") {
            $remoteShare = (string)($existingConfig["server_b"]["share"] ?? "");
        }
        if ($peerHost === "") {
            $peerHost = (string)($existingConfig["server_b"]["host"] ?? "");
        }
        if ($peerUser === "") {
            $peerUser = (string)($existingConfig["server_b"]["user"] ?? "root");
        }
        if ($peerUser === "" || preg_match("#[^A-Za-z0-9_.-]#", $peerUser)) {
            $errors[] = "Peer SSH user contains unsupported characters.";
        }
        $serverBRoot = $remoteShare !== "" ? "/mnt/user/" . $remoteShare : (string)($existingConfig["server_b"]["root"] ?? "/mnt/user/");
        $serverBConfiguredShare = $remoteShare;
    }
    if (!in_array($authority, ["server_a_preferred", "equal_peers"], true)) {
        $authority = "server_a_preferred";
    }

    if ($errors) {
        mirror_write_action("Settings not saved:\n" . implode("\n", $errors));
        mirror_redirect();
    }

    $serverARoot = "/mnt/user/" . $serverAShare;

    $config = [
        "server_a" => ["name" => "server-a", "root" => $serverARoot],
        "server_b" => [
            "name" => "server-b",
            "type" => $serverBType,
            "root" => $serverBRoot,
            "share" => $serverBConfiguredShare,
            "host" => $peerHost,
            "user" => $peerUser,
            "port" => $peerPort,
        ],
        "state_db" => "$configDir/mirror.sqlite3",
        "trash_root" => "$configDir/trash",
        "authority" => $authority,
        "delete_propagation" => $deletePropagation,
        "delete_behavior" => $deleteBehavior,
        "sync_interval" => $interval,
    ];

    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    file_put_contents(
        $configFile,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    $message = "Settings saved.";
    if ($wasRunning) {
        exec("/usr/local/sbin/mirrorctl restart 2>&1", $output, $code);
        $message .= "\n" . ($code === 0 ? "Daemon restarted." : "Daemon restart failed:");
        if ($output) {
            $message .= "\n" . implode("\n", $output);
        }
    }
    mirror_write_action($message);
    mirror_redirect();
}

if ($action === "generate-key") {
    if (!is_dir($sshDir)) {
        mkdir($sshDir, 0700, true);
    }
    if (!is_file($keyFile)) {
        $cmd = "ssh-keygen -t ed25519 -N '' -f " . escapeshellarg($keyFile) . " -C " . escapeshellarg("mirror-plugin@" . gethostname()) . " 2>&1";
        exec($cmd, $output, $code);
        chmod($keyFile, 0600);
        if (is_file($keyFile . ".pub")) {
            chmod($keyFile . ".pub", 0644);
        }
        mirror_write_action($code === 0 ? "SSH key generated." : "SSH key generation failed:\n" . implode("\n", $output));
    } else {
        mirror_write_action("SSH key already exists.");
    }
    mirror_redirect();
}

if ($action === "accept-peer-key") {
    global $rootSshDir, $authorizedKeysFile;
    $key = trim((string)($_POST["peer_public_key"] ?? ""));
    $errors = [];
    if ($key === "") {
        $errors[] = "Peer public key is required.";
    }
    if (!preg_match("#^ssh-ed25519\\s+[A-Za-z0-9+/=]+(?:\\s+.*)?$#", $key)) {
        $errors[] = "Only ssh-ed25519 public keys are accepted right now.";
    }
    if ($errors) {
        mirror_write_action("Peer key not accepted:\n" . implode("\n", $errors));
        mirror_redirect();
    }

    if (!is_dir($rootSshDir)) {
        mkdir($rootSshDir, 0700, true);
    }
    chmod($rootSshDir, 0700);
    $existing = is_file($authorizedKeysFile)
        ? file($authorizedKeysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : [];
    if (!in_array($key, $existing, true)) {
        $existing[] = $key;
        file_put_contents($authorizedKeysFile, implode("\n", $existing) . "\n");
    }
    chmod($authorizedKeysFile, 0600);
    mirror_write_action("Peer key accepted into /root/.ssh/authorized_keys.");
    mirror_redirect();
}

$allowed = ["status", "run-once", "start", "stop", "test-peer"];
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
