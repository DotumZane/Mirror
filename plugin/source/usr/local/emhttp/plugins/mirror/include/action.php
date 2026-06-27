<?php
$plugin = "mirror";
$configDir = "/boot/config/plugins/$plugin";
$configFile = "$configDir/config.json";
$actionFile = "$configDir/last-action.txt";
$discoveryFile = "$configDir/discovered-peers.json";
$pendingInvitesFile = "$configDir/pending-invites.json";
$peerFile = "$configDir/peer.json";
$versionFile = "/usr/local/emhttp/plugins/$plugin/VERSION";
$defaultConfigFile = "/usr/local/emhttp/plugins/$plugin/default-config.json";
$sshDir = "$configDir/ssh";
$keyFile = "$sshDir/mirror_ed25519";
$pidFile = "/var/run/$plugin.pid";
$rootSshDir = "/root/.ssh";
$authorizedKeysFile = "$rootSshDir/authorized_keys";
$pluginUrl = "https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg";
$pluginApiUrl = "https://api.github.com/repos/DotumZane/Mirror/contents/mirror.plg?ref=main";
$pairingPort = 23891;

function mirror_daemon_running() {
    global $pidFile;
    if (!is_file($pidFile)) {
        return false;
    }
    $pid = (int)trim((string)file_get_contents($pidFile));
    return $pid > 0 && posix_kill($pid, 0);
}

function mirror_is_ajax() {
    return (string)($_POST["ajax"] ?? "") === "1"
        || strtolower((string)($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "")) === "xmlhttprequest";
}

function mirror_redirect() {
    global $actionFile;
    if (mirror_is_ajax()) {
        header("Content-Type: application/json; charset=UTF-8");
        $message = is_file($actionFile) ? trim((string)file_get_contents($actionFile)) : "";
        echo json_encode(["ok" => true, "message" => $message], JSON_UNESCAPED_SLASHES) . "\n";
        exit;
    }
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

function mirror_role_locked(array $config): bool {
    return !array_key_exists("role_locked", $config) || !empty($config["role_locked"]);
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
    return array_keys($hosts);
}

function mirror_priority_lan_hosts($subnet, $selfIp) {
    if (!preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.0\/24$/', $subnet, $matches)) {
        return [];
    }
    $prefix = $matches[1];
    $hosts = [];
    $add = function ($lastOctet) use (&$hosts, $prefix, $selfIp) {
        $lastOctet = (int)$lastOctet;
        if ($lastOctet < 1 || $lastOctet > 254) {
            return;
        }
        $host = "$prefix.$lastOctet";
        if ($host !== $selfIp) {
            $hosts[$host] = true;
        }
    };
    if (preg_match('/\.(\d{1,3})$/', $selfIp, $selfMatch)) {
        $selfLast = (int)$selfMatch[1];
        for ($offset = 1; $offset <= 24; $offset++) {
            $add($selfLast - $offset);
            $add($selfLast + $offset);
        }
    }
    foreach ([1, 2, 3, 10, 11, 20, 25, 50, 68, 99, 100, 101, 150, 200, 220, 254] as $lastOctet) {
        $add($lastOctet);
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

function mirror_sshd_running() {
    exec("pgrep -x sshd 2>/dev/null", $pids, $runningCode);
    if ($runningCode === 0 && $pids) {
        return true;
    }
    $listeners = [];
    exec("(ss -ltn 2>/dev/null || netstat -ltn 2>/dev/null) | grep -E '(^|[[:space:]])[^[:space:]]*:22[[:space:]]' 2>/dev/null", $listeners, $listenCode);
    return $listenCode === 0 && $listeners;
}

function mirror_ensure_sshd() {
    if (mirror_sshd_running()) {
        return "running";
    }
    $output = [];
    $run = function ($label, $cmd) use (&$output) {
        $lines = [];
        exec($cmd . " 2>&1", $lines, $code);
        $output[] = "$label exit=$code";
        foreach ($lines as $line) {
            $output[] = "$label: $line";
        }
        usleep(250000);
        return mirror_sshd_running();
    };
    if (is_executable("/usr/bin/ssh-keygen")) {
        $run("ssh-keygen", "/usr/bin/ssh-keygen -A");
    }
    if (is_executable("/etc/rc.d/rc.sshd")) {
        if ($run("rc.sshd", "/etc/rc.d/rc.sshd start")) {
            return "started";
        }
    }
    if (is_executable("/usr/sbin/sshd")) {
        if ($run("sshd", "/usr/sbin/sshd")) {
            return "started";
        }
        if (is_file("/etc/ssh/sshd_config") && $run("sshd-config", "/usr/sbin/sshd -f /etc/ssh/sshd_config")) {
            return "started";
        }
    }
    throw new RuntimeException("SSH service did not start:\n" . implode("\n", $output));
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
    return mirror_ensure_sshd();
}

function mirror_http_json($url, $timeout = 1.2, $payload = null) {
    $curl = mirror_find_executable(["/usr/bin/curl", "/bin/curl"]);
    if ($curl === null) {
        return null;
    }
    $bodyFile = tempnam("/tmp", "mirror-body-");
    $cmd = escapeshellarg($curl)
        . " -sS --connect-timeout " . escapeshellarg((string)$timeout)
        . " --max-time " . escapeshellarg((string)$timeout)
        . " -H " . escapeshellarg("Cache-Control: no-cache")
        . " -H " . escapeshellarg("Pragma: no-cache")
        . " -o " . escapeshellarg($bodyFile)
        . " -w " . escapeshellarg("%{http_code}");
    $payloadFile = null;
    if ($payload !== null) {
        $payloadFile = tempnam("/tmp", "mirror-json-");
        file_put_contents($payloadFile, json_encode($payload, JSON_UNESCAPED_SLASHES));
        $cmd .= " -H " . escapeshellarg("Content-Type: application/json")
            . " --data-binary " . escapeshellarg("@$payloadFile");
    }
    $cmd .= " " . escapeshellarg($url) . " 2>&1";
    exec($cmd, $output, $code);
    if ($payloadFile !== null) {
        @unlink($payloadFile);
    }
    $body = is_file($bodyFile) ? (string)file_get_contents($bodyFile) : "";
    @unlink($bodyFile);
    $httpCode = trim(end($output) ?: "");
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        $decoded["_curl_code"] = $code;
        $decoded["_http_code"] = $httpCode;
        return $decoded;
    }
    $details = trim($body);
    if ($details === "") {
        $details = trim(implode("\n", $output));
    }
    if (strlen($details) > 500) {
        $details = substr($details, 0, 500) . "...";
    }
    return [
        "status" => "error",
        "error" => "No JSON response from $url"
            . ($httpCode !== "" ? " (HTTP $httpCode)" : "")
            . ($details !== "" ? ": $details" : ""),
        "_curl_code" => $code,
        "_http_code" => $httpCode,
    ];
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

function mirror_download_url($url, $target, $headers = []) {
    @unlink($target);
    $downloadOutput = [];
    $downloadCode = 1;

    $curl = mirror_find_executable(["/usr/bin/curl", "/bin/curl"]);
    if ($curl !== null) {
        $headerArgs = "";
        foreach ($headers as $header) {
            $headerArgs .= " -H " . escapeshellarg($header);
        }
        $cmd = escapeshellarg($curl) . " -fsSL" . $headerArgs . " -o " . escapeshellarg($target) . " " . escapeshellarg($url) . " 2>&1";
        exec($cmd, $downloadOutput, $downloadCode);
    } else {
        $wget = mirror_find_executable(["/usr/bin/wget", "/bin/wget"]);
        if ($wget !== null && !$headers) {
            $cmd = escapeshellarg($wget) . " -q -O " . escapeshellarg($target) . " " . escapeshellarg($url) . " 2>&1";
            exec($cmd, $downloadOutput, $downloadCode);
        } else {
            $context = null;
            if ($headers) {
                $context = stream_context_create(["http" => ["header" => implode("\r\n", $headers)]]);
            }
            $contents = @file_get_contents($url, false, $context);
            if ($contents !== false) {
                $downloadCode = @file_put_contents($target, $contents) === false ? 1 : 0;
            } else {
                $downloadOutput[] = "curl, wget, and PHP URL download all failed.";
            }
        }
    }

    if ($downloadCode !== 0 || !is_file($target) || filesize($target) < 10) {
        return [false, trim(implode("\n", $downloadOutput))];
    }

    return [true, trim(implode("\n", $downloadOutput))];
}

function mirror_validate_plugin_manifest($target) {
    if (!is_file($target) || filesize($target) < 100) {
        return false;
    }
    $head = (string)file_get_contents($target, false, null, 0, 4096);
    return stripos($head, "<!DOCTYPE PLUGIN") !== false || stripos($head, "<PLUGIN") !== false;
}

function mirror_download_plugin_from_api($url, $target) {
    $jsonTarget = $target . ".json";
    [$downloaded, $message] = mirror_download_url($url, $jsonTarget, [
        "Accept: application/vnd.github+json",
        "User-Agent: Mirror-Unraid-Plugin",
        "Cache-Control: no-cache",
    ]);
    if (!$downloaded) {
        return [false, $message];
    }
    $decoded = json_decode((string)file_get_contents($jsonTarget), true);
    @unlink($jsonTarget);
    if (!is_array($decoded) || !isset($decoded["content"])) {
        return [false, "GitHub API response did not include file content."];
    }
    $content = base64_decode((string)$decoded["content"], true);
    if ($content === false || @file_put_contents($target, $content) === false) {
        return [false, "Could not decode GitHub API file content."];
    }
    if (!mirror_validate_plugin_manifest($target)) {
        return [false, "GitHub API file content does not look like an Unraid plugin manifest."];
    }
    return [true, $message];
}

function mirror_download_plugin($url, $target) {
    [$downloaded, $message] = mirror_download_url($url, $target);
    if (!$downloaded) {
        return [false, $message];
    }
    if (!mirror_validate_plugin_manifest($target)) {
        return [false, "Downloaded file does not look like an Unraid plugin manifest."];
    }
    return [true, $message];
}

function mirror_plugin_manifest_version($path) {
    if (!is_file($path)) {
        return "";
    }
    $contents = (string)file_get_contents($path);
    if (preg_match('/<!ENTITY\s+version\s+"([^"]+)"/', $contents, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/<PLUGIN\b[^>]*\sversion="([^"]+)"/', $contents, $matches)) {
        return trim($matches[1]);
    }
    return "";
}

function mirror_existing_config() {
    global $configFile;
    $default = [
        "server_a" => [
            "name" => "server-a",
            "root" => "/mnt/user/mirror-test-a",
        ],
        "server_b" => [
            "type" => "local",
            "root" => "/mnt/user/mirror-test-b",
            "share" => "mirror-test-b",
            "host" => "",
            "user" => "root",
            "port" => 22,
        ],
        "node_role" => "master",
        "share_pairs" => [],
    ];
    if (!is_file($configFile)) {
        return $default;
    }
    $decoded = json_decode((string)file_get_contents($configFile), true);
    return is_array($decoded) ? array_replace_recursive($default, $decoded) : $default;
}

function mirror_write_config($config) {
    global $configDir, $configFile;
    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function mirror_configure_remote_peer($localShare, $peerHost, $peerShare, $authority, $deleteBehavior) {
    global $configDir;
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
    $sharePairs = [
        mirror_share_pair_config($localShare, $peerShare, "remote", $peerHost, "root", 22),
    ];
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
        "node_role" => (string)($existing["node_role"] ?? "master"),
        "role_locked" => mirror_role_locked($existing),
        "share_pairs" => $sharePairs,
        "sync_interval" => max(0, min(3600, (int)($existing["sync_interval"] ?? 10))),
    ];
    mirror_write_config($config);
}

function mirror_write_peer_profile($peer) {
    global $peerFile;
    $peer["linked_at"] = time();
    mirror_write_json_file($peerFile, $peer);
}

function mirror_preserve_mode_from_post() {
    global $configFile;
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
    mirror_write_config($config);
}

function mirror_apply_linked_peer_to_config($peer) {
    $peerHost = trim((string)($peer["host"] ?? ""));
    if ($peerHost === "") {
        return;
    }
    $config = mirror_existing_config();
    $config["server_b"] = is_array($config["server_b"] ?? null) ? $config["server_b"] : [];
    $config["server_b"]["type"] = "remote";
    $config["server_b"]["host"] = $peerHost;
    $config["server_b"]["user"] = (string)($config["server_b"]["user"] ?? "root");
    $config["server_b"]["port"] = (int)($config["server_b"]["port"] ?? 22);
    mirror_write_config($config);
}

function mirror_share_pair_config($localShare, $otherShare, $serverBType, $peerHost, $peerUser, $peerPort) {
    return [
        "server_a" => [
            "name" => "server-a",
            "root" => "/mnt/user/" . $localShare,
            "share" => $localShare,
        ],
        "server_b" => [
            "name" => "server-b",
            "type" => $serverBType,
            "root" => "/mnt/user/" . $otherShare,
            "share" => $otherShare,
            "host" => $peerHost,
            "user" => $peerUser,
            "port" => $peerPort,
        ],
    ];
}

function mirror_parse_additional_pairs($text) {
    $pairs = [];
    foreach (preg_split("/\r\n|\n|\r/", (string)$text) ?: [] as $line) {
        $line = trim($line);
        if ($line === "") {
            continue;
        }
        $parts = preg_split('/\s*(?:=|,|:)\s*/', $line, 2);
        if (!is_array($parts) || count($parts) !== 2 || trim($parts[0]) === "" || trim($parts[1]) === "") {
            throw new RuntimeException("Additional share pair must look like local-share=other-share: $line");
        }
        $pairs[] = [trim($parts[0]), trim($parts[1])];
    }
    return $pairs;
}

function mirror_linked_peer_host() {
    global $peerFile;
    $peer = mirror_json_file($peerFile, []);
    $peerHost = trim((string)($peer["host"] ?? ""));
    if ($peerHost !== "" && mirror_is_private_ip($peerHost)) {
        return $peerHost;
    }
    $config = mirror_existing_config();
    $configHost = trim((string)($config["server_b"]["host"] ?? ""));
    return mirror_is_private_ip($configHost) ? $configHost : "";
}

function mirror_control_linked_peer($command) {
    if (!in_array($command, ["start", "stop"], true)) {
        return "";
    }
    $config = mirror_existing_config();
    if (($config["node_role"] ?? "master") !== "master") {
        return "";
    }
    $peerHost = mirror_linked_peer_host();
    if ($peerHost === "") {
        return "\nRemote peer $command skipped: no linked private LAN peer.";
    }
    $response = mirror_http_json(mirror_remote_url($peerHost, ["action" => "control"]), 6.0, [
        "command" => $command,
    ]);
    if (!is_array($response) || ($response["status"] ?? "") !== "ok") {
        $response = mirror_http_json(mirror_remote_url($peerHost, [
            "action" => "control",
            "command" => $command,
            "transport" => "query",
        ]), 6.0);
    }
    if (!is_array($response)) {
        return "\nRemote peer $command failed: no response.";
    }
    $name = (string)($response["name"] ?? $peerHost);
    $code = (string)($response["code"] ?? "unknown");
    $output = trim((string)($response["output"] ?? ""));
    $statusAfter = trim((string)($response["status_after"] ?? ""));
    if (($response["status"] ?? "") === "ok") {
        $message = "\nRemote peer $command sent to $name. Code: $code.";
    } else {
        $error = (string)($response["error"] ?? json_encode($response, JSON_UNESCAPED_SLASHES));
        $message = "\nRemote peer $command failed on $name: $error";
        if ($code !== "unknown") {
            $message .= " Code: $code.";
        }
    }
    if ($output !== "") {
        $message .= "\nRemote output:\n" . $output;
    }
    if ($statusAfter !== "") {
        $message .= "\nRemote status after $command:\n" . $statusAfter;
    }
    return $message;
}

$action = $_POST["action"] ?? "status";

if ($action === "scan-peers") {
    global $discoveryFile;
    $subnet = trim((string)($_POST["scan_subnet"] ?? ""));
    $directHost = trim((string)($_POST["direct_host"] ?? ""));
    $deepScan = !empty($_POST["deep_scan"]);
    if ($subnet === "") {
        $ip = mirror_primary_ip();
        $subnet = preg_replace('/\.\d+$/', ".0/24", $ip);
    }
    mirror_write_json_file($discoveryFile, [
        "subnet" => $subnet,
        "direct_host" => $directHost,
        "deep_scan" => $deepScan,
        "scanned_at" => time(),
        "scan_status" => "running",
        "peers" => [],
    ]);
    exec("/usr/local/sbin/mirrorctl pair-restart 2>&1", $pairOutput, $pairCode);
    usleep(250000);
    $scanNonce = (string)time();
    $localCheck = mirror_http_json(mirror_remote_url("127.0.0.1", ["action" => "hello", "scan" => $scanNonce]), 1.0);
    $selfIp = mirror_primary_ip();
    $hosts = $deepScan ? mirror_subnet_hosts($subnet) : mirror_known_lan_hosts($subnet);
    if (!$deepScan) {
        $hosts = array_merge($hosts, mirror_priority_lan_hosts($subnet, $selfIp));
    }
    if ($directHost !== "" && filter_var($directHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        array_unshift($hosts, $directHost);
    }
    $hosts = array_values(array_unique($hosts));
    $found = [];
    $misses = [];
    $versionMismatches = [];
    foreach ($hosts as $host) {
        if ($host === "" || $host === $selfIp || !mirror_is_private_ip($host)) {
            continue;
        }
        $peer = mirror_http_json(mirror_remote_url($host, ["action" => "hello", "scan" => $scanNonce]), $deepScan ? 0.12 : 1.0);
        if (!is_array($peer) || ($peer["service"] ?? "") !== "mirror") {
            if ($host === $directHost) {
                $misses[] = [
                    "host" => $host,
                    "error" => is_array($peer) ? (string)($peer["error"] ?? json_encode($peer, JSON_UNESCAPED_SLASHES)) : "No response",
                ];
            }
            continue;
        }
        $peer["host"] = $host;
        $peer["found_at"] = time();
        $peer["scan_nonce"] = $scanNonce;
        $found[$host] = $peer;
        $peerVersion = (string)($peer["version"] ?? "");
        $localVersion = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : "";
        if ($peerVersion !== "" && $localVersion !== "" && $peerVersion !== $localVersion) {
            $versionMismatches[] = "$host reports $peerVersion; local Mirror is $localVersion"
                . "; responder pid " . (string)($peer["responder_pid"] ?? "unknown")
                . "; responder host " . (string)($peer["responder_host"] ?? "unknown")
                . "; version mtime " . (string)($peer["version_mtime"] ?? "unknown");
        }
    }
    mirror_write_json_file($discoveryFile, [
        "subnet" => $subnet,
        "direct_host" => $directHost,
        "deep_scan" => $deepScan,
        "scanned_at" => time(),
        "scan_status" => "complete",
        "misses" => $misses,
        "peers" => array_values($found),
    ]);
    $message = "LAN scan complete. Found " . count($found) . " Mirror peer" . (count($found) === 1 ? "." : "s.")
        . "\nPairing responder: " . (($pairCode === 0) ? "started/restarted" : "failed to restart")
        . "\nLocal responder test: " . ((is_array($localCheck) && ($localCheck["service"] ?? "") === "mirror") ? "ok" : "failed")
        . "\nHosts checked: " . count($hosts);
    if ($directHost !== "") {
        $message .= "\nDirect host: $directHost";
    } elseif (!$deepScan && count($found) === 0) {
        $message .= "\nQuick scan checked known LAN neighbors and nearby/common IPs. If the peer is still missing, enter the other server IP in Peer host or IP, or run Deep /24 scan.";
    } elseif ($deepScan && count($found) === 0) {
        $message .= "\nDeep scan found no peers. Enter the other server IP in Peer host or IP and scan again.";
    }
    foreach ($misses as $miss) {
        $message .= "\nDirect host failed: " . $miss["host"] . " - " . $miss["error"];
    }
    foreach ($versionMismatches as $mismatch) {
        $message .= "\nVersion mismatch: $mismatch. Restart Pairing Responder on that peer, then scan again.";
    }
    if ($pairOutput) {
        $message .= "\nResponder output:\n" . implode("\n", $pairOutput);
    }
    mirror_write_action($message);
    mirror_redirect();
}

if ($action === "pair-restart") {
    exec("/usr/local/sbin/mirrorctl pair-restart 2>&1", $output, $code);
    $message = $code === 0 ? "Pairing responder restarted." : "Pairing responder restart failed:";
    if ($output) {
        $message .= "\n" . implode("\n", $output);
    }
    mirror_write_action($message);
    mirror_redirect();
}

if ($action === "check-responder") {
    $installedVersion = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : "unknown";
    $response = mirror_http_json(mirror_remote_url("127.0.0.1", ["action" => "hello", "check" => time()]), 1.0);
    $message = "Local pairing responder check."
        . "\nInstalled version file: $installedVersion";
    if (is_array($response) && ($response["service"] ?? "") === "mirror") {
        $message .= "\nResponder version: " . (string)($response["version"] ?? "unknown")
            . "\nResponder name: " . (string)($response["name"] ?? "unknown")
            . "\nResponder host: " . (string)($response["responder_host"] ?? "unknown")
            . "\nResponder pid: " . (string)($response["responder_pid"] ?? "unknown")
            . "\nVersion mtime: " . (string)($response["version_mtime"] ?? "unknown")
            . "\nScript mtime: " . (string)($response["script_mtime"] ?? "unknown");
        if ((string)($response["version"] ?? "") !== $installedVersion) {
            $message .= "\nMismatch: restart the pairing responder, then check again.";
        }
    } else {
        $message .= "\nResponder check failed: " . (is_array($response) ? (string)($response["error"] ?? json_encode($response, JSON_UNESCAPED_SLASHES)) : "No response");
    }
    mirror_write_action($message);
    mirror_redirect();
}

if ($action === "clear-discovery") {
    global $discoveryFile;
    $subnet = trim((string)($_POST["scan_subnet"] ?? ""));
    if ($subnet === "") {
        $ip = mirror_primary_ip();
        $subnet = preg_replace('/\.\d+$/', ".0/24", $ip);
    }
    mirror_write_json_file($discoveryFile, [
        "subnet" => $subnet,
        "direct_host" => "",
        "deep_scan" => false,
        "scanned_at" => 0,
        "scan_status" => "cleared",
        "peers" => [],
    ]);
    mirror_write_action("LAN discovery results cleared. Run Find Mirror Servers again.");
    mirror_redirect();
}

if ($action === "save-role") {
    $role = trim((string)($_POST["node_role"] ?? "master"));
    if (!in_array($role, ["master", "managed_remote"], true)) {
        $role = "master";
    }
    $config = mirror_existing_config();
    $currentRole = (string)($config["node_role"] ?? "master");
    if (!in_array($currentRole, ["master", "managed_remote"], true)) {
        $currentRole = "master";
    }
    if (mirror_role_locked($config) && $role !== $currentRole) {
        mirror_write_action("Server role not changed. Factory reset Mirror before switching between Master and Managed remote.");
        mirror_redirect();
    }
    $config["node_role"] = $role;
    $config["role_locked"] = true;
    mirror_write_config($config);
    mirror_write_action($role === "managed_remote"
        ? "Server role saved: Managed remote. Configure shares and sync rules from the master server."
        : "Server role saved: Master. Configure pairing, shares, and sync rules on this server.");
    mirror_redirect();
}

if ($action === "invite-peer") {
    global $discoveryFile;
    try {
        $peerHost = trim((string)($_POST["peer_host"] ?? ""));
        exec("/usr/local/sbin/mirrorctl pair-start 2>&1", $pairOutput, $pairCode);
        if ($peerHost === "" || !mirror_is_private_ip($peerHost)) {
            throw new RuntimeException("Peer host must be a private LAN IP address.");
        }
        mirror_write_json_file($discoveryFile, [
            "subnet" => preg_replace('/\.\d+$/', ".0/24", mirror_primary_ip()),
            "direct_host" => $peerHost,
            "deep_scan" => false,
            "scanned_at" => 0,
            "scan_status" => "direct-invite",
            "peers" => [],
        ]);
        $publicKey = mirror_ensure_key();
        $payload = [
            "action" => "invite",
            "from_name" => mirror_server_name(),
            "from_host" => mirror_primary_ip(),
            "public_key" => $publicKey,
        ];
        $hello = mirror_http_json(mirror_remote_url($peerHost, ["action" => "hello", "invite_check" => time()]), 2.0);
        if (!is_array($hello) || ($hello["service"] ?? "") !== "mirror") {
            $helloError = is_array($hello) ? (string)($hello["error"] ?? json_encode($hello, JSON_UNESCAPED_SLASHES)) : "no response";
            throw new RuntimeException("Peer responder did not answer hello before invite: $helloError");
        }
        $response = mirror_http_json(mirror_remote_url($peerHost, ["action" => "invite"]), 4.0, $payload);
        if (!is_array($response) || ($response["status"] ?? "") !== "pending") {
            $postError = is_array($response) ? (string)($response["error"] ?? json_encode($response, JSON_UNESCAPED_SLASHES)) : "no response";
            $response = mirror_http_json(mirror_remote_url($peerHost, [
                "action" => "invite",
                "from_name" => $payload["from_name"],
                "from_host" => $payload["from_host"],
                "public_key" => $payload["public_key"],
                "transport" => "query",
            ]), 4.0);
            if (!is_array($response) || ($response["status"] ?? "") !== "pending") {
                $queryError = is_array($response) ? (string)($response["error"] ?? json_encode($response, JSON_UNESCAPED_SLASHES)) : "no response";
                throw new RuntimeException("Peer did not store the invite request. POST: $postError. Query fallback: $queryError");
            }
        }
        $peerVersion = (string)($response["version"] ?? "unknown");
        mirror_accept_public_key((string)($response["public_key"] ?? ""));
        mirror_write_peer_profile([
            "name" => (string)($response["name"] ?? $peerHost),
            "host" => $peerHost,
            "version" => $peerVersion,
            "shares" => is_array($response["shares"] ?? null) ? $response["shares"] : [],
            "public_key" => (string)($response["public_key"] ?? ""),
            "status" => "invite_sent",
        ]);
        mirror_write_action(
            "Invite sent to " . ($response["name"] ?? $peerHost) . "."
            . "\nPeer version: " . $peerVersion
            . "\nPeer link is staged on this server."
            . "\nReceiving host: $peerHost"
            . "\nInvite ID: " . ($response["id"] ?? "unknown")
            . "\nNow open Mirror on the peer server and click Accept under Pending Invites."
            . "\nAfter it is accepted, choose shares in Share Pair."
        );
    } catch (Throwable $error) {
        $extra = "";
        if (!empty($pairOutput)) {
            $extra = "\nLocal responder check:\n" . implode("\n", $pairOutput);
        }
        mirror_write_action("Invite failed:\n" . $error->getMessage() . $extra);
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

if ($action === "check-invites") {
    global $pendingInvitesFile;
    $pending = mirror_json_file($pendingInvitesFile, ["invites" => []]);
    $invites = is_array($pending["invites"] ?? null) ? $pending["invites"] : [];
    mirror_write_action("Pending invite check complete. Found " . count($invites) . " invite" . (count($invites) === 1 ? "." : "s."));
    mirror_redirect();
}

if ($action === "use-linked-peer") {
    global $peerFile;
    $peer = mirror_json_file($peerFile, []);
    if (empty($peer["host"])) {
        mirror_write_action("No linked peer found. Invite and accept a peer first.");
        mirror_redirect();
    }
    mirror_apply_linked_peer_to_config($peer);
    mirror_write_action("Linked peer applied to Share Pair. Choose local and remote shares, then Save Settings.");
    mirror_redirect();
}

if ($action === "refresh-peer-shares") {
    global $peerFile;
    try {
        $peer = mirror_json_file($peerFile, []);
        $peerHost = trim((string)($peer["host"] ?? ""));
        if ($peerHost === "" || !mirror_is_private_ip($peerHost)) {
            throw new RuntimeException("No linked private LAN peer was found.");
        }
        $response = mirror_http_json(mirror_remote_url($peerHost, ["action" => "hello", "shares" => time()]), 3.0);
        if (!is_array($response) || ($response["service"] ?? "") !== "mirror") {
            $peerError = is_array($response) ? (string)($response["error"] ?? json_encode($response, JSON_UNESCAPED_SLASHES)) : "no response";
            throw new RuntimeException("Peer did not return its share list: $peerError");
        }
        $shares = is_array($response["shares"] ?? null) ? array_values($response["shares"]) : [];
        $peer["name"] = (string)($response["name"] ?? ($peer["name"] ?? $peerHost));
        $peer["version"] = (string)($response["version"] ?? ($peer["version"] ?? "unknown"));
        $peer["shares"] = $shares;
        $peer["shares_refreshed_at"] = time();
        mirror_write_peer_profile($peer);
        mirror_apply_linked_peer_to_config($peer);
        mirror_write_action("Remote shares refreshed from " . ($peer["name"] ?? $peerHost) . ". Found " . count($shares) . " share" . (count($shares) === 1 ? "." : "s.") . "\nRemote LAN mirror mode selected.");
    } catch (Throwable $error) {
        mirror_write_action("Remote shares not refreshed:\n" . $error->getMessage());
    }
    mirror_redirect();
}

if ($action === "save-config") {
    $wasRunning = mirror_daemon_running();
    $existingConfig = mirror_existing_config();
    if (($existingConfig["node_role"] ?? "master") === "managed_remote") {
        mirror_write_action("Settings not saved. This server is Managed remote; configure share pairing and sync rules from the master server.");
        mirror_redirect();
    }
    $serverAShare = trim((string)($_POST["server_a_share"] ?? ""));
    $mirrorMode = trim((string)($_POST["mirror_mode"] ?? ""));
    $serverBType = $mirrorMode !== "" ? $mirrorMode : trim((string)($_POST["server_b_type"] ?? "local"));
    $serverBShare = trim((string)($_POST["server_b_share"] ?? ""));
    $remoteShare = trim((string)($_POST["remote_share"] ?? ""));
    $additionalPairsText = (string)($_POST["additional_share_pairs"] ?? "");
    $peerHost = trim((string)($_POST["peer_host"] ?? ""));
    $peerUser = trim((string)($_POST["peer_user"] ?? "root"));
    $peerPort = max(1, min(65535, (int)($_POST["peer_port"] ?? 22)));
    $authority = $_POST["authority"] ?? "server_a_preferred";
    $deleteBehavior = $_POST["delete_behavior"] ?? "restore_missing";
    $interval = max(0, min(3600, (int)($_POST["sync_interval"] ?? 10)));
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
        $peer = mirror_json_file($peerFile, []);
        $peerShares = is_array($peer["shares"] ?? null) ? $peer["shares"] : [];
        $linkedPeerHost = trim((string)($peer["host"] ?? ""));
        if ($remoteShare !== "" && $peerShares && !in_array($remoteShare, $peerShares, true)) {
            $errors[] = "Remote peer share must be selected from the linked peer's current shares.";
        }
        if ($remoteShare === "") {
            $remoteShare = (string)($existingConfig["server_b"]["share"] ?? "");
        }
        if ($linkedPeerHost !== "") {
            $peerHost = $linkedPeerHost;
        }
        if ($peerHost === "") {
            $peerHost = (string)($existingConfig["server_b"]["host"] ?? "");
        }
        if ($peerUser === "") {
            $peerUser = (string)($existingConfig["server_b"]["user"] ?? "root");
        }
        if ($peerUser === "" || preg_match("#[^A-Za-z0-9_.-]#", $peerUser)) {
            $errors[] = "Peer user contains unsupported characters.";
        }
        $serverBRoot = $remoteShare !== "" ? "/mnt/user/" . $remoteShare : (string)($existingConfig["server_b"]["root"] ?? "/mnt/user/");
        $serverBConfiguredShare = $remoteShare;
    }
    if (!in_array($authority, ["server_a_preferred", "equal_peers"], true)) {
        $authority = "server_a_preferred";
    }

    $sharePairs = [];
    if (!$errors) {
        $sharePairs[] = mirror_share_pair_config($serverAShare, $serverBConfiguredShare, $serverBType, $peerHost, $peerUser, $peerPort);
        try {
            $seenLocalShares = [$serverAShare => true];
            foreach (mirror_parse_additional_pairs($additionalPairsText) as $pair) {
                [$localShare, $otherShare] = $pair;
                if (preg_match("#[\\x00/]+#", $localShare) || preg_match("#[\\x00/]+#", $otherShare)) {
                    $errors[] = "Additional share pairs must use share names, not paths.";
                    continue;
                }
                if (!isset($shares[$localShare])) {
                    $errors[] = "Additional local share does not exist: $localShare";
                    continue;
                }
                if (isset($seenLocalShares[$localShare])) {
                    $errors[] = "Additional share pair repeats local share: $localShare";
                    continue;
                }
                if ($serverBType === "local") {
                    if (!isset($shares[$otherShare])) {
                        $errors[] = "Additional second local share does not exist: $otherShare";
                        continue;
                    }
                    if ($localShare === $otherShare) {
                        $errors[] = "Additional pair cannot mirror a share to itself: $localShare";
                        continue;
                    }
                } elseif ($peerShares && !in_array($otherShare, $peerShares, true)) {
                    $errors[] = "Additional remote share must be selected from the linked peer's current shares: $otherShare";
                    continue;
                }
                $seenLocalShares[$localShare] = true;
                $sharePairs[] = mirror_share_pair_config($localShare, $otherShare, $serverBType, $peerHost, $peerUser, $peerPort);
            }
        } catch (Throwable $pairError) {
            $errors[] = $pairError->getMessage();
        }
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
        "node_role" => (string)($existingConfig["node_role"] ?? "master"),
        "role_locked" => mirror_role_locked($existingConfig),
        "state_db" => "$configDir/mirror.sqlite3",
        "trash_root" => "$configDir/trash",
        "authority" => $authority,
        "delete_propagation" => $deletePropagation,
        "delete_behavior" => $deleteBehavior,
        "share_pairs" => $sharePairs,
        "sync_interval" => $interval,
    ];

    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    mirror_write_config($config);
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

if ($action === "factory-reset") {
    global $configDir, $configFile, $defaultConfigFile, $discoveryFile, $pendingInvitesFile, $peerFile, $sshDir;
    if (empty($_POST["confirm_factory_reset"])) {
        mirror_write_action("Factory reset not run. Check Confirm factory reset first.");
        mirror_redirect();
    }
    exec("/usr/local/sbin/mirrorctl stop 2>&1", $stopOutput, $stopCode);
    exec("/usr/local/sbin/mirrorctl pair-stop 2>&1", $pairOutput, $pairCode);
    $paths = [
        $configFile,
        $discoveryFile,
        $pendingInvitesFile,
        $peerFile,
        "$configDir/last-action.txt",
        "/var/log/mirror.log",
        "/var/log/mirror-pairing.log",
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    foreach (["$configDir/trash", $sshDir] as $dir) {
        if (is_dir($dir)) {
            exec("rm -rf " . escapeshellarg($dir) . " 2>&1");
        }
    }
    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    if (is_file($defaultConfigFile)) {
        copy($defaultConfigFile, $configFile);
    }
    exec("/usr/local/sbin/mirrorctl pair-start 2>&1", $startPairOutput, $startPairCode);
    $message = "Factory reset complete."
        . "\nCleared Mirror config, pairing state, LAN scan cache, transfer keys, trash, and logs."
        . "\nPreserved the sync index database and did not touch user shares or synced files.";
    $outputs = array_merge($stopOutput ?: [], $pairOutput ?: [], $startPairOutput ?: []);
    if ($outputs) {
        $message .= "\nCommand output:\n" . implode("\n", $outputs);
    }
    mirror_write_action($message);
    mirror_redirect();
}

if ($action === "update-plugin") {
    global $pluginUrl, $pluginApiUrl, $versionFile;
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
    $manifestSource = "GitHub API";
    [$downloaded, $downloadMessage] = mirror_download_plugin_from_api($pluginApiUrl, $localPlugin);
    if (!$downloaded) {
        $apiMessage = $downloadMessage;
        $manifestSource = "raw GitHub fallback";
        [$downloaded, $downloadMessage] = mirror_download_plugin($downloadUrl, $localPlugin);
        if ($downloadMessage !== "") {
            $downloadMessage = "GitHub API failed: $apiMessage\nRaw fallback: $downloadMessage";
        } else {
            $downloadMessage = "GitHub API failed: $apiMessage\nRaw fallback downloaded the manifest.";
        }
    }
    if (!$downloaded) {
        $message = "Plugin update command failed:\nCould not download plugin manifest."
            . "\nAPI URL: $pluginApiUrl"
            . "\nRaw URL: $downloadUrl"
            . "\n$downloadMessage";
        if ($usePopup) {
            mirror_output_window("Plugin Update - Finished", $message);
        }
        mirror_write_action($message);
        mirror_redirect();
    }
    $installedVersion = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : "unknown";
    $downloadedVersion = mirror_plugin_manifest_version($localPlugin);
    if ($downloadedVersion !== "" && $installedVersion === $downloadedVersion) {
        $message = "Plugin update check finished."
            . "\nInstalled version: $installedVersion"
            . "\nDownloaded manifest version: $downloadedVersion"
            . "\nManifest source: $manifestSource"
            . "\nNo update installed because Unraid will not reinstall the same plugin version."
            . "\nIf you expected a newer build, push the latest commit to GitHub, then try Update Plugin again.";
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
        . "\nManifest source: $manifestSource"
        . "\nAPI URL: $pluginApiUrl"
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

$allowed = ["status", "run-once", "initial-sync", "start", "stop", "test-peer"];
if (!in_array($action, $allowed, true)) {
    $action = "status";
}

$cmd = "/usr/local/sbin/mirrorctl " . escapeshellarg($action) . " 2>&1";
exec($cmd, $output, $code);
$message = implode("\n", $output);
if ($code !== 0) {
    $message = "Command failed:\n" . $message;
} elseif (in_array($action, ["start", "stop"], true)) {
    $message .= mirror_control_linked_peer($action);
}
mirror_write_action($message);
mirror_redirect();
