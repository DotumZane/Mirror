<?php
$plugin = "mirror";
$configDir = "/boot/config/plugins/$plugin";
$configFile = "$configDir/config.json";
$actionFile = "$configDir/last-action.txt";
$discoveryFile = "$configDir/discovered-peers.json";
$pendingInvitesFile = "$configDir/pending-invites.json";
$peerFile = "$configDir/peer.json";
$sshDir = "$configDir/ssh";
$keyFile = "$sshDir/mirror_ed25519";
$pidFile = "/var/run/$plugin.pid";
$rootSshDir = "/root/.ssh";
$authorizedKeysFile = "$rootSshDir/authorized_keys";
$pluginUrl = "https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg";
$pairingPort = 23891;

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
    echo "html,body{height:100%;margin:0;background:#1f1c1c;color:#ddd;font-family:monospace;font-size:13px;}";
    echo "body{display:grid;grid-template-rows:auto 1fr auto;}";
    echo "header{background:#555;color:#fff;font-family:Arial,sans-serif;font-size:18px;font-weight:700;padding:18px;text-align:center;}";
    echo "pre{white-space:pre-wrap;margin:0;padding:22px;line-height:1.45;overflow:auto;}";
    echo "footer{padding:14px 18px;text-align:center;}";
    echo "button{border:1px solid #ff6a2a;background:transparent;color:#ff9b6f;font-weight:700;padding:7px 18px;}";
    echo "</style></head><body>";
    echo "<header>$safeTitle</header><pre>$safeMessage</pre><footer><button onclick=\"if(parent&&parent.closeMirrorUpdateModal){parent.closeMirrorUpdateModal();}else{window.close();}\">Done</button></footer>";
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

function mirror_server_name() {
    $ident = "/boot/config/ident.cfg";
    if (is_file($ident)) {
        $contents = (string)file_get_contents($ident);
        if (preg_match('/^NAME="?(.*?)"?$/m', $contents, $matches)) {
            $name = trim($matches[1], "\" \t\r\n");
            if ($name !== "") {
                return $name;
            }
        }
    }
    return gethostname() ?: "unraid";
}

function mirror_primary_ip() {
    $output = trim((string)shell_exec("ip -o -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if (\$i==\"src\") {print \$(i+1); exit}}'"));
    if (filter_var($output, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $output;
    }
    $hostIp = gethostbyname(gethostname() ?: "");
    return filter_var($hostIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $hostIp : "";
}

function mirror_is_private_ip($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $long = ip2long($ip);
    $ranges = [
        ["10.0.0.0", "10.255.255.255"],
        ["172.16.0.0", "172.31.255.255"],
        ["192.168.0.0", "192.168.255.255"],
    ];
    foreach ($ranges as [$start, $end]) {
        if ($long >= ip2long($start) && $long <= ip2long($end)) {
            return true;
        }
    }
    return false;
}

function mirror_subnet_hosts($subnet) {
    if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.0\/24$/', $subnet, $matches)) {
        return [];
    }
    $octets = array_map("intval", array_slice($matches, 1));
    foreach ($octets as $octet) {
        if ($octet < 0 || $octet > 255) {
            return [];
        }
    }
    $prefix = implode(".", $octets);
    $hosts = [];
    for ($i = 1; $i <= 254; $i++) {
        $hosts[] = "$prefix.$i";
    }
    return $hosts;
}

function mirror_known_lan_hosts($subnet) {
    $hosts = [];
    $output = (string)shell_exec("(ip neigh show 2>/dev/null || arp -an 2>/dev/null) | grep -Eo '([0-9]{1,3}\\.){3}[0-9]{1,3}'");
    foreach (preg_split('/\s+/', trim($output)) as $host) {
        if (mirror_is_private_ip($host)) {
            $hosts[$host] = true;
        }
    }
    foreach (mirror_subnet_hosts($subnet) as $host) {
        if (isset($hosts[$host])) {
            continue;
        }
    }
    return array_keys($hosts);
}

function mirror_json_file($path, $default = []) {
    if (!is_file($path)) {
        return $default;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $default;
}

function mirror_write_json_file($path, $data) {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function mirror_ensure_key() {
    global $sshDir, $keyFile;
    if (!is_dir($sshDir)) {
        mkdir($sshDir, 0700, true);
    }
    if (!is_file($keyFile)) {
        $cmd = "ssh-keygen -t ed25519 -N '' -f " . escapeshellarg($keyFile) . " -C " . escapeshellarg("mirror-plugin@" . mirror_server_name()) . " 2>&1";
        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new RuntimeException("SSH key generation failed:\n" . implode("\n", $output));
        }
    }
    chmod($keyFile, 0600);
    if (is_file($keyFile . ".pub")) {
        chmod($keyFile . ".pub", 0644);
    }
    return trim((string)file_get_contents($keyFile . ".pub"));
}

function mirror_accept_public_key($key) {
    global $rootSshDir, $authorizedKeysFile;
    $key = trim((string)$key);
    if (!preg_match("#^ssh-ed25519\\s+[A-Za-z0-9+/=]+(?:\\s+.*)?$#", $key)) {
        throw new RuntimeException("Only ssh-ed25519 public keys are accepted right now.");
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
}

function mirror_http_json($url, $timeout = 1.2, $payload = null) {
    $curl = mirror_find_executable(["/usr/bin/curl", "/bin/curl"]);
    if ($curl === null) {
        return null;
    }
    $cmd = escapeshellarg($curl)
        . " -sSL --connect-timeout " . escapeshellarg((string)$timeout)
        . " --max-time " . escapeshellarg((string)$timeout);
    $payloadFile = null;
    if ($payload !== null) {
        $payloadFile = tempnam("/tmp", "mirror-json-");
        file_put_contents($payloadFile, json_encode($payload, JSON_UNESCAPED_SLASHES));
        $cmd .= " -H " . escapeshellarg("Content-Type: application/json")
            . " --data-binary " . escapeshellarg("@$payloadFile");
    }
    $cmd .= " " . escapeshellarg($url) . " 2>/dev/null";
    exec($cmd, $output, $code);
    if ($payloadFile !== null) {
        @unlink($payloadFile);
    }
    $decoded = json_decode(implode("\n", $output), true);
    if (is_array($decoded)) {
        $decoded["_curl_code"] = $code;
        return $decoded;
    }
    return ["status" => "error", "error" => "No JSON response from $url", "_curl_code" => $code];
}

function mirror_remote_url($host, $query) {
    global $pairingPort;
    return "http://" . $host . ":" . $pairingPort . "/?" . http_build_query($query);
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

function mirror_configure_remote_peer($localShare, $peerHost, $peerShare, $authority, $deleteBehavior) {
    global $configDir, $configFile;
    $localShare = trim((string)$localShare);
    $peerShare = trim((string)$peerShare);
    $peerHost = trim((string)$peerHost);
    if ($localShare === "" || $peerShare === "" || $peerHost === "") {
        throw new RuntimeException("Local share, peer host, and peer share are required.");
    }
    $shares = mirror_current_shares();
    if (!isset($shares[$localShare])) {
        throw new RuntimeException("Local share must be selected from current /mnt/user shares.");
    }
    if (preg_match("#[\\x00/]+#", $peerShare)) {
        throw new RuntimeException("Peer share must be a single share name, not a path.");
    }
    if (!filter_var($peerHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !preg_match("#^[A-Za-z0-9_.-]+$#", $peerHost)) {
        throw new RuntimeException("Peer host contains unsupported characters.");
    }
    if (!in_array($authority, ["server_a_preferred", "equal_peers"], true)) {
        $authority = "equal_peers";
    }
    if (!in_array($deleteBehavior, ["restore_missing", "mirror_deletes"], true)) {
        $deleteBehavior = "mirror_deletes";
    }
    $existing = mirror_existing_config();
    $config = [
        "server_a" => ["name" => mirror_server_name(), "root" => "/mnt/user/" . $localShare],
        "server_b" => [
            "name" => "server-b",
            "type" => "remote",
            "root" => "/mnt/user/" . $peerShare,
            "share" => $peerShare,
            "host" => $peerHost,
            "user" => "root",
            "port" => 22,
        ],
        "state_db" => "$configDir/mirror.sqlite3",
        "trash_root" => "$configDir/trash",
        "authority" => $authority,
        "delete_propagation" => $deleteBehavior === "mirror_deletes",
        "delete_behavior" => $deleteBehavior,
        "sync_interval" => max(1, min(3600, (int)($existing["sync_interval"] ?? 10))),
    ];
    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function mirror_write_peer_profile($peer) {
    global $peerFile;
    $peer["linked_at"] = time();
    mirror_write_json_file($peerFile, $peer);
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

if ($action === "scan-peers") {
    global $discoveryFile;
    exec("/usr/local/sbin/mirrorctl pair-restart 2>&1", $pairOutput, $pairCode);
    usleep(250000);
    $localCheck = mirror_http_json(mirror_remote_url("127.0.0.1", ["action" => "hello"]), 1.0);
    $subnet = trim((string)($_POST["scan_subnet"] ?? ""));
    $directHost = trim((string)($_POST["direct_host"] ?? ""));
    $deepScan = !empty($_POST["deep_scan"]);
    if ($subnet === "") {
        $ip = mirror_primary_ip();
        $subnet = preg_replace('/\.\d+$/', ".0/24", $ip);
    }
    $hosts = $deepScan ? mirror_subnet_hosts($subnet) : mirror_known_lan_hosts($subnet);
    if ($directHost !== "" && filter_var($directHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        array_unshift($hosts, $directHost);
    }
    $hosts = array_values(array_unique($hosts));
    $selfIp = mirror_primary_ip();
    $found = [];
    foreach ($hosts as $host) {
        if ($host === "" || $host === $selfIp || !mirror_is_private_ip($host)) {
            continue;
        }
        $peer = mirror_http_json(mirror_remote_url($host, ["action" => "hello"]), $deepScan ? 0.35 : 1.0);
        if (!is_array($peer) || ($peer["service"] ?? "") !== "mirror") {
            continue;
        }
        $peer["host"] = $host;
        $peer["found_at"] = time();
        $found[$host] = $peer;
    }
    mirror_write_json_file($discoveryFile, [
        "subnet" => $subnet,
        "deep_scan" => $deepScan,
        "scanned_at" => time(),
        "peers" => array_values($found),
    ]);
    $message = "LAN scan complete. Found " . count($found) . " Mirror peer" . (count($found) === 1 ? "." : "s.")
        . "\nPairing responder: " . (($pairCode === 0) ? "started/restarted" : "failed to restart")
        . "\nLocal responder test: " . ((is_array($localCheck) && ($localCheck["service"] ?? "") === "mirror") ? "ok" : "failed")
        . "\nHosts checked: " . count($hosts);
    if ($directHost !== "") {
        $message .= "\nDirect host: $directHost";
    }
    if ($pairOutput) {
        $message .= "\nResponder output:\n" . implode("\n", $pairOutput);
    }
    mirror_write_action($message);
    mirror_redirect();
}

if ($action === "invite-peer") {
    global $discoveryFile;
    try {
        $peerHost = trim((string)($_POST["peer_host"] ?? ""));
        $publicKey = mirror_ensure_key();
        $payload = [
            "action" => "invite",
            "from_name" => mirror_server_name(),
            "from_host" => mirror_primary_ip(),
            "public_key" => $publicKey,
        ];
        $response = mirror_http_json(mirror_remote_url($peerHost, ["action" => "invite"]), 4.0, $payload);
        if (!is_array($response) || ($response["status"] ?? "") !== "pending") {
            $peerError = is_array($response) ? (string)($response["error"] ?? json_encode($response, JSON_UNESCAPED_SLASHES)) : "no response";
            throw new RuntimeException("Peer did not store the invite request: $peerError");
        }
        mirror_accept_public_key((string)($response["public_key"] ?? ""));
        mirror_write_peer_profile([
            "name" => (string)($response["name"] ?? $peerHost),
            "host" => $peerHost,
            "version" => (string)($response["version"] ?? "unknown"),
            "shares" => is_array($response["shares"] ?? null) ? $response["shares"] : [],
            "public_key" => (string)($response["public_key"] ?? ""),
            "status" => "invite_sent",
        ]);
        mirror_write_action(
            "Invite sent to " . ($response["name"] ?? $peerHost) . "."
            . "\nPeer link is staged on this server."
            . "\nNow open Mirror on the peer server and click Accept under Pending Invites."
            . "\nAfter it is accepted, choose shares in Share Pair."
        );
    } catch (Throwable $error) {
        mirror_write_action("Invite failed:\n" . $error->getMessage());
    }
    mirror_redirect();
}

if ($action === "accept-invite") {
    global $pendingInvitesFile;
    try {
        $inviteId = trim((string)($_POST["invite_id"] ?? ""));
        $pending = mirror_json_file($pendingInvitesFile, ["invites" => []]);
        $invites = is_array($pending["invites"] ?? null) ? $pending["invites"] : [];
        if (!isset($invites[$inviteId])) {
            throw new RuntimeException("Pending invite was not found.");
        }
        $invite = $invites[$inviteId];
        mirror_accept_public_key((string)($invite["public_key"] ?? ""));
        mirror_write_peer_profile([
            "name" => (string)($invite["from_name"] ?? $invite["from_host"] ?? "Mirror peer"),
            "host" => (string)($invite["from_host"] ?? ""),
            "version" => "unknown",
            "shares" => [],
            "public_key" => (string)($invite["public_key"] ?? ""),
            "status" => "linked",
        ]);
        unset($invites[$inviteId]);
        mirror_write_json_file($pendingInvitesFile, ["invites" => $invites]);
        mirror_write_action(
            "Invite accepted."
            . "\nThis server is now paired with " . ($invite["from_name"] ?? $invite["from_host"] ?? "peer") . "."
            . "\nRemote host: " . ($invite["from_host"] ?? "")
            . "\nNow choose shares in Share Pair."
        );
    } catch (Throwable $error) {
        mirror_write_action("Invite accept failed:\n" . $error->getMessage());
    }
    mirror_redirect();
}

if ($action === "reject-invite") {
    global $pendingInvitesFile;
    $inviteId = trim((string)($_POST["invite_id"] ?? ""));
    $pending = mirror_json_file($pendingInvitesFile, ["invites" => []]);
    $invites = is_array($pending["invites"] ?? null) ? $pending["invites"] : [];
    unset($invites[$inviteId]);
    mirror_write_json_file($pendingInvitesFile, ["invites" => $invites]);
    mirror_write_action("Invite rejected.");
    mirror_redirect();
}

if ($action === "use-linked-peer") {
    global $peerFile;
    $peer = mirror_json_file($peerFile, []);
    if (empty($peer["host"])) {
        mirror_write_action("No linked peer found. Invite and accept a peer first.");
        mirror_redirect();
    }
    $config = mirror_existing_config();
    $config["server_b"] = is_array($config["server_b"] ?? null) ? $config["server_b"] : [];
    $config["server_b"]["type"] = "remote";
    $config["server_b"]["host"] = (string)$peer["host"];
    $config["server_b"]["user"] = "root";
    $config["server_b"]["port"] = 22;
    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    mirror_write_action("Linked peer applied to Share Pair. Choose local and remote shares, then Save Settings.");
    mirror_redirect();
}

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
            mirror_output_window("Plugin Update - Finished", $message);
        }
        mirror_write_action($message);
        mirror_redirect();
    }
    $localPlugin = "/tmp/mirror.plg";
    $downloadUrl = $pluginUrl . "?mirror_cache_bust=" . rawurlencode((string)time());
    [$downloaded, $downloadMessage] = mirror_download_plugin($downloadUrl, $localPlugin);
    if (!$downloaded) {
        $message = "Plugin update command failed:\nCould not download plugin manifest from $downloadUrl.\n$downloadMessage";
        if ($usePopup) {
            mirror_output_window("Plugin Update - Finished", $message);
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
        mirror_output_window("Plugin Update - Finished", $message);
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
