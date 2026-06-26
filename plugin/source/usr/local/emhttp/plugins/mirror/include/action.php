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
$pluginUrl = "https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg";

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

function mirror_output_window($title, $message) {
    header("Content-Type: text/html; charset=UTF-8");
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, "UTF-8");
    $safeMessage = htmlspecialchars(trim($message), ENT_QUOTES, "UTF-8");
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>$safeTitle</title>";
    echo "<style>";
    echo "html,body{margin:0;background:#1f1c1c;color:#ddd;font-family:monospace;font-size:13px;}";
    echo "header{background:#555;color:#fff;font-family:Arial,sans-serif;font-size:18px;font-weight:700;padding:14px;text-align:center;}";
    echo "pre{white-space:pre-wrap;margin:0;padding:18px;line-height:1.45;}";
    echo "footer{padding:12px 18px;border-top:1px solid #444;text-align:center;}";
    echo "button{border:1px solid #ff6a2a;background:transparent;color:#ff9b6f;font-weight:700;padding:7px 18px;}";
    echo "</style></head><body>";
    echo "<header>$safeTitle</header><pre>$safeMessage</pre><footer><button onclick=\"window.close()\">Done</button></footer>";
    echo "</body></html>";
    exit;
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

function mirror_find_executable($candidates) {
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function mirror_download_plugin($url, $target) {
    @unlink($target);
    $downloadOutput = [];
    $downloadCode = 1;

    $curl = mirror_find_executable(["/usr/bin/curl", "/bin/curl"]);
    if ($curl !== null) {
        $cmd = escapeshellarg($curl) . " -fsSL -o " . escapeshellarg($target) . " " . escapeshellarg($url) . " 2>&1";
        exec($cmd, $downloadOutput, $downloadCode);
    } else {
        $wget = mirror_find_executable(["/usr/bin/wget", "/bin/wget"]);
        if ($wget !== null) {
            $cmd = escapeshellarg($wget) . " -q -O " . escapeshellarg($target) . " " . escapeshellarg($url) . " 2>&1";
            exec($cmd, $downloadOutput, $downloadCode);
        } else {
            $contents = @file_get_contents($url);
            if ($contents !== false) {
                $downloadCode = @file_put_contents($target, $contents) === false ? 1 : 0;
            } else {
                $downloadOutput[] = "curl, wget, and PHP URL download all failed.";
            }
        }
    }

    if ($downloadCode !== 0 || !is_file($target) || filesize($target) < 100) {
        return [false, trim(implode("\n", $downloadOutput))];
    }

    $head = (string)file_get_contents($target, false, null, 0, 4096);
    if (stripos($head, "<!DOCTYPE PLUGIN") === false && stripos($head, "<PLUGIN") === false) {
        return [false, "Downloaded file does not look like an Unraid plugin manifest."];
    }

    return [true, trim(implode("\n", $downloadOutput))];
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

function mirror_preserve_mode_from_post() {
    global $configDir, $configFile;
    $mode = trim((string)($_POST["preserve_mirror_mode"] ?? ""));
    if (!in_array($mode, ["local", "remote"], true) || !is_file($configFile)) {
        return;
    }
    $config = json_decode((string)file_get_contents($configFile), true);
    if (!is_array($config)) {
        return;
    }
    $config["server_b"] = is_array($config["server_b"] ?? null) ? $config["server_b"] : [];
    $config["server_b"]["type"] = $mode;
    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    file_put_contents(
        $configFile,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
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
    mirror_preserve_mode_from_post();
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
    mirror_preserve_mode_from_post();
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

if ($action === "update-plugin") {
    global $pluginUrl;
    $usePopup = (string)($_POST["popup"] ?? "") === "1";
    $pluginCli = mirror_find_executable(["/usr/local/sbin/plugin", "/usr/sbin/plugin", "/sbin/plugin", "/usr/local/bin/plugin", "/usr/bin/plugin"]);
    $installplg = mirror_find_executable(["/usr/local/sbin/installplg", "/usr/sbin/installplg", "/sbin/installplg"]);
    if ($pluginCli === null && $installplg === null) {
        $message = "Plugin update command failed:\n"
            . "Neither Unraid's plugin CLI nor installplg was found in expected paths.";
        if ($usePopup) {
            mirror_output_window("Mirror Plugin Update", $message);
        }
        mirror_write_action($message);
        mirror_redirect();
    }
    $localPlugin = "/tmp/mirror-latest.plg";
    $downloadUrl = $pluginUrl . "?mirror_cache_bust=" . rawurlencode((string)time());
    [$downloaded, $downloadMessage] = mirror_download_plugin($downloadUrl, $localPlugin);
    if (!$downloaded) {
        $message = "Plugin update command failed:\nCould not download plugin manifest from $downloadUrl.\n$downloadMessage";
        if ($usePopup) {
            mirror_output_window("Mirror Plugin Update", $message);
        }
        mirror_write_action($message);
        mirror_redirect();
    }
    if ($pluginCli !== null) {
        $cmd = escapeshellarg($pluginCli) . " install " . escapeshellarg($localPlugin) . " 2>&1";
        $runner = "$pluginCli install";
    } else {
        $cmd = escapeshellarg($installplg) . " " . escapeshellarg($localPlugin) . " 2>&1";
        $runner = $installplg;
    }
    exec($cmd, $output, $code);
    $message = ($code === 0 ? "Plugin update command finished." : "Plugin update command failed:")
        . "\nManifest: $pluginUrl"
        . "\nDownload URL: $downloadUrl"
        . "\nLocal file: $localPlugin"
        . "\nCommand: $runner"
        . "\n" . implode("\n", $output);
    if ($usePopup) {
        mirror_output_window("Mirror Plugin Update", $message);
    }
    mirror_write_action($message);
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
