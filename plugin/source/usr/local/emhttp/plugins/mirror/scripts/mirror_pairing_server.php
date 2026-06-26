<?php
$plugin = "mirror";
$configDir = "/boot/config/plugins/$plugin";
$pendingInvitesFile = "$configDir/pending-invites.json";
$actionFile = "$configDir/last-action.txt";
$sshDir = "$configDir/ssh";
$keyFile = "$sshDir/mirror_ed25519";
$rootSshDir = "/root/.ssh";
$authorizedKeysFile = "$rootSshDir/authorized_keys";
$versionFile = "/usr/local/emhttp/plugins/$plugin/VERSION";

function mirror_json_response($data, $status = 200) {
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
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

function mirror_current_shares() {
    $shares = [];
    foreach (glob("/mnt/user/*", GLOB_ONLYDIR) ?: [] as $path) {
        $name = basename($path);
        if ($name === "" || $name[0] === ".") {
            continue;
        }
        $shares[] = $name;
    }
    natcasesort($shares);
    return array_values($shares);
}

function mirror_primary_ip() {
    $output = trim((string)shell_exec("ip -o -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if (\$i==\"src\") {print \$(i+1); exit}}'"));
    if (filter_var($output, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $output;
    }
    $hostIp = gethostbyname(gethostname() ?: "");
    return filter_var($hostIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $hostIp : "";
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
            mirror_json_response(["status" => "error", "error" => "SSH key generation failed."], 500);
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
    mirror_json_response([
        "status" => "error",
        "error" => "SSH service did not start.",
        "output" => implode("\n", $output),
    ], 500);
}

function mirror_accept_public_key($key) {
    global $rootSshDir, $authorizedKeysFile;
    $key = trim((string)$key);
    if (!preg_match("#^ssh-ed25519\\s+[A-Za-z0-9+/=]+(?:\\s+.*)?$#", $key)) {
        mirror_json_response(["status" => "error", "error" => "Only ssh-ed25519 public keys are accepted."], 400);
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

function mirror_write_action($message) {
    global $configDir, $actionFile;
    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    file_put_contents($actionFile, trim($message));
}

function mirror_private_remote() {
    $ip = $_SERVER["REMOTE_ADDR"] ?? "";
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

$action = $_GET["action"] ?? "hello";
clearstatcache(true, $versionFile);
$version = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : "unknown";

if ($action === "hello") {
    mirror_json_response([
        "service" => "mirror",
        "name" => mirror_server_name(),
        "version" => $version,
        "responder_pid" => getmypid(),
        "responder_host" => mirror_primary_ip(),
        "version_file" => $versionFile,
        "version_mtime" => is_file($versionFile) ? filemtime($versionFile) : 0,
        "script_mtime" => filemtime(__FILE__) ?: 0,
        "shares" => mirror_current_shares(),
    ]);
}

if ($action === "invite") {
    if (!mirror_private_remote()) {
        mirror_json_response(["status" => "error", "error" => "LAN invites are only accepted from private IPv4 addresses."], 403);
    }
    $payload = json_decode((string)file_get_contents("php://input"), true);
    if (!is_array($payload)) {
        $payload = $_GET;
    }
    $fromHost = trim((string)($payload["from_host"] ?? ($_SERVER["REMOTE_ADDR"] ?? "")));
    $fromName = trim((string)($payload["from_name"] ?? "Mirror peer"));
    $publicKey = trim((string)($payload["public_key"] ?? ""));
    if ($fromHost === "" || !preg_match("#^ssh-ed25519\\s+[A-Za-z0-9+/=]+(?:\\s+.*)?$#", $publicKey)) {
        mirror_json_response(["status" => "error", "error" => "Invite is missing host or public key."], 400);
    }
    $pending = mirror_json_file($pendingInvitesFile, ["invites" => []]);
    $invites = is_array($pending["invites"] ?? null) ? $pending["invites"] : [];
    $id = hash("sha256", $fromHost . "|" . $publicKey);
    $invites[$id] = [
        "id" => $id,
        "from_name" => $fromName,
        "from_host" => $fromHost,
        "public_key" => $publicKey,
        "created_at" => time(),
    ];
    mirror_write_json_file($pendingInvitesFile, ["invites" => $invites]);
    mirror_write_action(
        "Pending invite received from $fromName."
        . "\nPeer host: $fromHost"
        . "\nOpen LAN Peer Setup and click Accept under Pending Invites."
    );
    mirror_json_response([
        "status" => "pending",
        "id" => $id,
        "name" => mirror_server_name(),
        "version" => $version,
        "shares" => mirror_current_shares(),
        "public_key" => mirror_ensure_key(),
    ]);
}

if ($action === "ensure-ssh") {
    if (!mirror_private_remote()) {
        mirror_json_response(["status" => "error", "error" => "SSH setup is only accepted from private IPv4 addresses."], 403);
    }
    $payload = json_decode((string)file_get_contents("php://input"), true);
    if (!is_array($payload)) {
        $payload = $_GET;
    }
    $publicKey = trim((string)($payload["public_key"] ?? ""));
    $sshd = mirror_accept_public_key($publicKey);
    mirror_json_response([
        "status" => "ok",
        "name" => mirror_server_name(),
        "version" => $version,
        "sshd" => $sshd,
        "public_key" => mirror_ensure_key(),
    ]);
}

mirror_json_response(["status" => "error", "error" => "Unknown action."], 404);
