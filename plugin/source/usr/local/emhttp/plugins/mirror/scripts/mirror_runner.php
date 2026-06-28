<?php
declare(strict_types=1);

class MirrorPeerUnavailable extends RuntimeException {}

function usage(): int {
    fwrite(STDERR, "Usage: mirror_runner.php {interval|run-once|initial-sync|daemon|test-peer} --config <path> [--interval <seconds>]\n");
    return 2;
}

function option_value(array $args, string $name, ?string $default = null): ?string {
    $index = array_search($name, $args, true);
    if ($index === false || !isset($args[$index + 1])) {
        return $default;
    }
    return $args[$index + 1];
}

function load_config(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException("config not found: $path");
    }
    $config = json_decode((string)file_get_contents($path), true);
    if (!is_array($config)) {
        throw new RuntimeException("config is not valid JSON: $path");
    }
    return $config;
}

function safe_interval(array $config): int {
    return max(0, min(3600, (int)($config["sync_interval"] ?? 10)));
}

function equal_peer_delete_enabled(array $config): bool {
    $deleteBehavior = (string)($config["delete_behavior"] ?? "");
    $deletePropagation = $deleteBehavior === "mirror_deletes"
        || ($deleteBehavior === "" && !empty($config["delete_propagation"]));
    return (($config["authority"] ?? "server_a_preferred") === "equal_peers")
        && $deletePropagation;
}

function delete_mirroring_enabled(array $config): bool {
    $deleteBehavior = (string)($config["delete_behavior"] ?? "");
    return $deleteBehavior === "mirror_deletes"
        || ($deleteBehavior === "" && !empty($config["delete_propagation"]));
}

function endpoint_is_remote(array $config): bool {
    return (($config["server_b"]["type"] ?? "local") === "remote");
}

function node_is_managed_remote(array $config): bool {
    return (($config["node_role"] ?? "master") === "managed_remote");
}

function ssh_key_path(string $configPath): string {
    return dirname($configPath) . "/ssh/mirror_ed25519";
}

function ssh_known_hosts_path(string $configPath): string {
    return dirname($configPath) . "/ssh/known_hosts";
}

function mirror_server_name(): string {
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

function ensure_ssh_key(string $configPath): string {
    $key = ssh_key_path($configPath);
    $keyDir = dirname($key);
    if (!is_dir($keyDir) && !mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
        throw new RuntimeException("could not create transfer key directory: $keyDir");
    }
    if (!is_file($key)) {
        $cmd = [
            "ssh-keygen",
            "-t", "ed25519",
            "-N", "",
            "-f", $key,
            "-C", "mirror-plugin@" . mirror_server_name(),
        ];
        run_command($cmd);
    }
    chmod($key, 0600);
    if (is_file($key . ".pub")) {
        chmod($key . ".pub", 0644);
    }
    return $key;
}

function ensure_ssh_known_hosts_file(string $configPath): string {
    $knownHosts = ssh_known_hosts_path($configPath);
    $knownHostsDir = dirname($knownHosts);
    if (!is_dir($knownHostsDir) && !mkdir($knownHostsDir, 0700, true) && !is_dir($knownHostsDir)) {
        throw new RuntimeException("could not create known hosts directory: $knownHostsDir");
    }
    if (!is_file($knownHosts)) {
        file_put_contents($knownHosts, "");
    }
    chmod($knownHosts, 0600);
    return $knownHosts;
}

function ssh_target(array $config): string {
    $user = (string)($config["server_b"]["user"] ?? "root");
    $host = (string)($config["server_b"]["host"] ?? "");
    if ($host === "") {
        throw new RuntimeException("peer host is not configured");
    }
    return $user . "@" . $host;
}

function ssh_base_args(array $config, string $configPath): array {
    $port = max(1, min(65535, (int)($config["server_b"]["port"] ?? 22)));
    $key = ensure_ssh_key($configPath);
    $knownHosts = ensure_ssh_known_hosts_file($configPath);
    ensure_remote_ssh_ready($config, $configPath);
    return [
        "ssh",
        "-i", $key,
        "-p", (string)$port,
        "-o", "BatchMode=yes",
        "-o", "UserKnownHostsFile=" . $knownHosts,
        "-o", "StrictHostKeyChecking=accept-new",
        "-o", "ConnectTimeout=8",
    ];
}

function shell_command(array $parts): string {
    return implode(" ", array_map("escapeshellarg", $parts));
}

function run_command(array $parts): string {
    $cmd = shell_command($parts) . " 2>&1";
    exec($cmd, $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ($code): " . implode("\n", $output));
    }
    return implode("\n", $output);
}

function run_command_to_log(array $parts): string {
    $cmd = shell_command($parts) . " 2>&1";
    passthru($cmd, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ($code): " . $cmd);
    }
    return "";
}

function http_json(string $url, array $payload = [], float $timeout = 4.0, bool $post = true): array {
    $curl = trim((string)shell_exec("command -v curl 2>/dev/null"));
    if ($curl === "") {
        throw new RuntimeException("curl is required for peer setup but was not found");
    }
    $bodyFile = tempnam("/tmp", "mirror-body-");
    $payloadFile = $post ? tempnam("/tmp", "mirror-json-") : false;
    if ($bodyFile === false || ($post && $payloadFile === false)) {
        throw new RuntimeException("could not create temporary files for peer setup");
    }
    $requestUrl = $url;
    if ($post) {
        file_put_contents((string)$payloadFile, json_encode($payload, JSON_UNESCAPED_SLASHES));
    } elseif ($payload) {
        $separator = strpos($requestUrl, "?") === false ? "?" : "&";
        $requestUrl .= $separator . http_build_query($payload);
    }
    $cmd = escapeshellarg($curl)
        . " -sS --connect-timeout " . escapeshellarg((string)$timeout)
        . " --max-time " . escapeshellarg((string)$timeout)
        . " -H " . escapeshellarg("Cache-Control: no-cache")
        . " -o " . escapeshellarg($bodyFile)
        . " -w " . escapeshellarg("%{http_code}");
    if ($post) {
        $cmd .= " -H " . escapeshellarg("Content-Type: application/json")
            . " --data-binary @" . escapeshellarg((string)$payloadFile);
    }
    $cmd .= " " . escapeshellarg($requestUrl) . " 2>&1";
    exec($cmd, $output, $code);
    $httpCode = (int)trim((string)end($output));
    $body = is_file($bodyFile) ? (string)file_get_contents($bodyFile) : "";
    @unlink($bodyFile);
    if ($payloadFile !== false) {
        @unlink($payloadFile);
    }
    if ($code !== 0 || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("peer setup request failed (HTTP $httpCode): " . trim($body . "\n" . implode("\n", $output)));
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        $snippet = trim(substr($body, 0, 500));
        throw new RuntimeException("peer setup returned invalid JSON (HTTP $httpCode, " . strlen($body) . " bytes): " . ($snippet !== "" ? $snippet : "[empty body]"));
    }
    return $decoded;
}

function refresh_peer_known_host(array $config, string $configPath): void {
    $host = (string)($config["server_b"]["host"] ?? "");
    if ($host === "") {
        return;
    }
    $port = max(1, min(65535, (int)($config["server_b"]["port"] ?? 22)));
    $knownHosts = ensure_ssh_known_hosts_file($configPath);
    $removeTarget = $port === 22 ? $host : "[" . $host . "]:" . $port;
    $sshKeygen = trim((string)shell_exec("command -v ssh-keygen 2>/dev/null"));
    if ($sshKeygen !== "") {
        shell_exec(escapeshellarg($sshKeygen) . " -R " . escapeshellarg($removeTarget) . " -f " . escapeshellarg($knownHosts) . " >/dev/null 2>&1");
    }
    $sshKeyscan = trim((string)shell_exec("command -v ssh-keyscan 2>/dev/null"));
    if ($sshKeyscan !== "") {
        $scan = shell_exec(escapeshellarg($sshKeyscan) . " -T 5 -p " . escapeshellarg((string)$port) . " -H " . escapeshellarg($host) . " 2>/dev/null");
        if (is_string($scan) && trim($scan) !== "") {
            file_put_contents($knownHosts, $scan, FILE_APPEND);
            chmod($knownHosts, 0600);
        }
    }
}

function ensure_remote_ssh_ready(array $config, string $configPath): void {
    static $ready = [];
    $host = (string)($config["server_b"]["host"] ?? "");
    $port = max(1, min(65535, (int)($config["server_b"]["port"] ?? 22)));
    $readyKey = $host . ":" . $port;
    if ($host === "" || isset($ready[$readyKey])) {
        return;
    }
    $publicKeyFile = ensure_ssh_key($configPath) . ".pub";
    if (!is_file($publicKeyFile)) {
        throw new RuntimeException("local transfer public key is missing");
    }
    $payload = [
        "public_key" => trim((string)file_get_contents($publicKeyFile)),
        "from_name" => mirror_server_name(),
    ];
    try {
        $response = http_json("http://" . $host . ":23891/?action=ensure-ssh", $payload, 4.0, true);
    } catch (Throwable $postError) {
        try {
            $response = http_json("http://" . $host . ":23891/?action=ensure-ssh", $payload + ["transport" => "query"], 4.0, false);
        } catch (Throwable $queryError) {
            throw new MirrorPeerUnavailable("peer setup responder unavailable at {$host}:23891; check that Mirror is installed/running on the remote server and LAN pairing responder is started");
        }
    }
    if (($response["status"] ?? "") !== "ok") {
        throw new RuntimeException("peer SSH setup failed: " . json_encode($response, JSON_UNESCAPED_SLASHES));
    }
    refresh_peer_known_host($config, $configPath);
    $ready[$readyKey] = true;
}

function remote_path(string $root, string $rel = ""): string {
    $path = rtrim($root, "/");
    if ($rel !== "") {
        $path .= "/" . ltrim($rel, "/");
    }
    return $path;
}

function remote_spec(array $config, string $path): string {
    return ssh_target($config) . ":" . $path;
}

function parse_find_listing(string $output): array {
    $files = [];
    foreach (explode("\n", rtrim($output, "\r\n")) as $line) {
        if ($line === "") {
            continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) < 3 || $parts[0] === "" || strpos($parts[0], ".mirror") === 0) {
            continue;
        }
        $files[$parts[0]] = [
            "exists" => true,
            "sig" => $parts[1] . ":" . (string)((int)floor((float)$parts[2])),
        ];
    }
    return $files;
}

function scan_files_php(string $root): array {
    $files = [];
    if (!is_dir($root)) {
        return $files;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $rel = ltrim(substr($path, strlen(rtrim($root, "/"))), "/");
        if ($rel === "" || strpos($rel, ".mirror") === 0) {
            continue;
        }
        $files[$rel] = [
            "exists" => true,
            "sig" => $file->getSize() . ":" . $file->getMTime(),
        ];
    }
    return $files;
}

function scan_files(string $root): array {
    if (!is_dir($root)) {
        return [];
    }
    $find = trim((string)shell_exec("command -v find 2>/dev/null"));
    if ($find !== "") {
        try {
            $output = run_command([$find, $root, "-type", "f", "-printf", "%P\t%s\t%T@\n"]);
            return parse_find_listing($output);
        } catch (Throwable $findError) {
            log_event("local find scan unavailable, falling back to PHP scanner: " . $findError->getMessage());
        }
    }
    return scan_files_php($root);
}

function scan_remote_files(array $config, string $configPath, string $root): array {
    $ssh = ssh_base_args($config, $configPath);
    $target = ssh_target($config);
    $script = "[ -d " . escapeshellarg($root) . " ] && find " . escapeshellarg($root) . " -type f -printf '%P\\t%s\\t%T@\\n' || true";
    $output = run_command(array_merge($ssh, [$target, $script]));
    return parse_find_listing($output);
}

function state_for(string $path): array {
    if (!is_file($path)) {
        return ["exists" => false, "sig" => null];
    }
    return ["exists" => true, "sig" => filesize($path) . ":" . filemtime($path)];
}

function remote_state_for(array $scan, string $rel): array {
    return $scan[$rel] ?? ["exists" => false, "sig" => null];
}

function state_path(string $configPath): string {
    return dirname($configPath) . "/state.json";
}

function load_state(string $configPath): array {
    $path = state_path($configPath);
    if (!is_file($path)) {
        return ["files" => [], "conflicts" => []];
    }
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state)) {
        return ["files" => [], "conflicts" => []];
    }
    $state["files"] = is_array($state["files"] ?? null) ? $state["files"] : [];
    $state["conflicts"] = is_array($state["conflicts"] ?? null) ? $state["conflicts"] : [];
    $state["pairs"] = is_array($state["pairs"] ?? null) ? $state["pairs"] : [];
    return $state;
}

function save_state(string $configPath, array $state): void {
    $path = state_path($configPath);
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function trash_path(array $config, string $endpointName, string $root, string $rel, string $reason): bool {
    $source = rtrim($root, "/") . "/" . $rel;
    if (!is_file($source)) {
        return false;
    }
    $stamp = date("Ymd-His");
    $destination = rtrim((string)$config["trash_root"], "/") . "/" . $endpointName . "/" . $reason . "/" . $rel . "." . $stamp;
    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0777, true);
    }
    return copy($source, $destination);
}

function copy_file(array $config, string $sourceName, string $sourceRoot, string $targetName, string $targetRoot, string $rel, array &$summary): void {
    $source = rtrim($sourceRoot, "/") . "/" . $rel;
    $target = rtrim($targetRoot, "/") . "/" . $rel;
    if (!is_file($source)) {
        $summary["conflicts"]++;
        return;
    }
    if (is_file($target)) {
        if (trash_path($config, $targetName, $targetRoot, $rel, "overwritten")) {
            $summary["trashed"]++;
        }
    }
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }
    if (!copy($source, $target)) {
        throw new RuntimeException("failed to copy $source to $target");
    }
    touch($target, filemtime($source));
    $summary["copied"]++;
}

function rsync_ssh_option(array $config, string $configPath): string {
    $ssh = ssh_base_args($config, $configPath);
    return implode(" ", array_map("escapeshellarg", $ssh));
}

function copy_local_to_remote(array $config, string $configPath, string $sourceRoot, string $targetRoot, string $rel, array &$summary): void {
    $source = remote_path($sourceRoot, $rel);
    if (!is_file($source)) {
        $summary["conflicts"]++;
        return;
    }
    $remoteDir = dirname(remote_path($targetRoot, $rel));
    run_command(array_merge(ssh_base_args($config, $configPath), [ssh_target($config), "mkdir -p " . escapeshellarg($remoteDir)]));
    $cmd = [
        "rsync",
        "-a",
        "-e", rsync_ssh_option($config, $configPath),
        $source,
        remote_spec($config, remote_path($targetRoot, $rel)),
    ];
    run_command($cmd);
    $summary["copied"]++;
}

function copy_remote_to_local(array $config, string $configPath, string $sourceRoot, string $targetRoot, string $rel, array &$summary): void {
    $target = remote_path($targetRoot, $rel);
    if (is_file($target)) {
        if (trash_path($config, "server-a", $targetRoot, $rel, "overwritten")) {
            $summary["trashed"]++;
        }
    }
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }
    $cmd = [
        "rsync",
        "-a",
        "-e", rsync_ssh_option($config, $configPath),
        remote_spec($config, remote_path($sourceRoot, $rel)),
        $target,
    ];
    run_command($cmd);
    $summary["copied"]++;
}

function initial_sync(string $configPath): array {
    $config = load_config($configPath);
    if (node_is_managed_remote($config)) {
        return empty_summary();
    }
    $summary = empty_summary();
    foreach (configured_pair_configs($config) as $pairConfig) {
        if (!empty($pairConfig["config"]["paused"])) {
            log_event("route " . route_label($pairConfig["config"]) . ": paused, skipping initial sync");
            continue;
        }
        $pairSummary = initial_sync_pair($configPath, $pairConfig["config"], $pairConfig["key"]);
        add_summary($summary, $pairSummary);
    }
    return $summary;
}

function initial_sync_pair(string $configPath, array $config, string $stateKey): array {
    $aRoot = rtrim((string)$config["server_a"]["root"], "/");
    $bRoot = rtrim((string)$config["server_b"]["root"], "/");
    if ($aRoot === "" || $bRoot === "") {
        throw new RuntimeException("initial sync requires both share roots");
    }
    if (!is_dir($aRoot)) {
        throw new RuntimeException("local source share does not exist: $aRoot");
    }
    echo date("c") . " initial sync starting: $aRoot -> $bRoot\n";
    if (endpoint_is_remote($config)) {
        run_command(array_merge(ssh_base_args($config, $configPath), [ssh_target($config), "mkdir -p " . escapeshellarg($bRoot)]));
        run_command_to_log([
            "rsync",
            "-a",
            "--info=progress2",
            "-e", rsync_ssh_option($config, $configPath),
            rtrim($aRoot, "/") . "/",
            remote_spec($config, rtrim($bRoot, "/") . "/"),
        ]);
    } else {
        if (!is_dir($bRoot) && !mkdir($bRoot, 0777, true) && !is_dir($bRoot)) {
            throw new RuntimeException("could not create target share root: $bRoot");
        }
        run_command_to_log([
            "rsync",
            "-a",
            "--info=progress2",
            rtrim($aRoot, "/") . "/",
            rtrim($bRoot, "/") . "/",
        ]);
    }
    echo date("c") . " initial sync copy finished; recording baseline state\n";
    return endpoint_is_remote($config)
        ? sync_once_remote($configPath, $config, $stateKey)
        : sync_once_pair($configPath, $config, $stateKey);
}

function trash_remote_file(array $config, string $configPath, string $root, string $rel, string $reason, array &$summary): void {
    $source = remote_path($root, $rel);
    $trashRoot = "/boot/config/plugins/mirror/trash/server-b/" . $reason;
    $destination = $trashRoot . "/" . $rel . "." . date("Ymd-His");
    $script = "[ -f " . escapeshellarg($source) . " ] && mkdir -p " . escapeshellarg(dirname($destination)) . " && cp -p " . escapeshellarg($source) . " " . escapeshellarg($destination) . " || true";
    run_command(array_merge(ssh_base_args($config, $configPath), [ssh_target($config), $script]));
    $summary["trashed"]++;
}

function delete_remote_file(array $config, string $configPath, string $root, string $rel, array &$summary): void {
    trash_remote_file($config, $configPath, $root, $rel, "deleted", $summary);
    $target = remote_path($root, $rel);
    run_command(array_merge(ssh_base_args($config, $configPath), [ssh_target($config), "rm -f " . escapeshellarg($target)]));
    $summary["deleted"]++;
}

function delete_file(array $config, string $endpointName, string $root, string $rel, array &$summary): void {
    $path = rtrim($root, "/") . "/" . $rel;
    if (!is_file($path)) {
        return;
    }
    if (trash_path($config, $endpointName, $root, $rel, "deleted")) {
        $summary["trashed"]++;
    }
    unlink($path);
    $summary["deleted"]++;
}

function record_file(array &$state, string $rel, string $aRoot, string $bRoot, string $status): void {
    $a = state_for(rtrim($aRoot, "/") . "/" . $rel);
    $b = state_for(rtrim($bRoot, "/") . "/" . $rel);
    $state["files"][$rel] = [
        "a_sig" => $a["sig"],
        "b_sig" => $b["sig"],
        "status" => $status,
        "updated_at" => time(),
    ];
}

function record_file_states(array &$state, string $rel, array $a, array $b, string $status): void {
    $state["files"][$rel] = [
        "a_sig" => $a["sig"],
        "b_sig" => $b["sig"],
        "status" => $status,
        "updated_at" => time(),
    ];
}

function record_conflict(array &$state, string $rel, string $reason, string $aRoot, string $bRoot, array &$summary): void {
    $state["conflicts"][] = [
        "path" => $rel,
        "reason" => $reason,
        "created_at" => time(),
    ];
    record_file($state, $rel, $aRoot, $bRoot, "conflict");
    $summary["conflicts"]++;
}

function record_conflict_states(array &$state, string $rel, string $reason, array $a, array $b, array &$summary): void {
    $state["conflicts"][] = [
        "path" => $rel,
        "reason" => $reason,
        "created_at" => time(),
    ];
    record_file_states($state, $rel, $a, $b, "conflict");
    $summary["conflicts"]++;
}

function empty_summary(): array {
    return ["copied" => 0, "deleted" => 0, "trashed" => 0, "conflicts" => 0, "unchanged" => 0];
}

function add_summary(array &$target, array $source): void {
    foreach (["copied", "deleted", "trashed", "conflicts", "unchanged"] as $key) {
        $target[$key] += (int)($source[$key] ?? 0);
    }
}

function log_event(string $message): void {
    echo date("c") . " " . $message . "\n";
    flush();
}

function route_label(array $config): string {
    $name = trim((string)($config["name"] ?? ""));
    if ($name !== "") {
        return $name;
    }
    $aRoot = (string)($config["server_a"]["root"] ?? "");
    $bShare = (string)($config["server_b"]["share"] ?? "");
    $bRoot = (string)($config["server_b"]["root"] ?? "");
    $a = basename(rtrim($aRoot, "/")) ?: "server-a";
    $b = $bShare !== "" ? $bShare : (basename(rtrim($bRoot, "/")) ?: "server-b");
    return $a . " -> " . $b;
}

function log_route_action(array $config, string $action, string $rel, string $detail = ""): void {
    $line = "route " . route_label($config) . ": " . $action;
    if ($rel !== "") {
        $line .= " " . $rel;
    }
    if ($detail !== "") {
        $line .= " (" . $detail . ")";
    }
    log_event($line);
}

function log_route_progress(array $config, int $processed, int $total, string $rel): void {
    if ($total <= 0) {
        return;
    }
    $percent = (int)floor(($processed / $total) * 100);
    log_route_action($config, "index progress {$processed}/{$total} ({$percent}%)", $rel);
}

function mark_progress(array &$state, string $phase, int $processed, int $total, string $currentPath = ""): void {
    $state["_meta"] = [
        "phase" => $phase,
        "processed" => max(0, $processed),
        "total" => max(0, $total),
        "current_path" => $currentPath,
        "updated_at" => time(),
    ];
}

function should_checkpoint_progress(int $processed, int $total, float &$lastCheckpointAt): bool {
    if ($processed >= $total) {
        return true;
    }
    if ($processed > 0 && $processed % 250 === 0) {
        $lastCheckpointAt = microtime(true);
        return true;
    }
    $now = microtime(true);
    if (($now - $lastCheckpointAt) >= 2.0) {
        $lastCheckpointAt = $now;
        return true;
    }
    return false;
}

function pair_key(array $pair): string {
    $a = (string)($pair["server_a"]["root"] ?? "");
    $b = (string)($pair["server_b"]["root"] ?? "");
    $peer = (string)($pair["server_b"]["peer_id"] ?? $pair["server_b"]["host"] ?? "");
    return substr(hash("sha256", $a . "|" . $peer . "|" . $b), 0, 16);
}

function configured_pair_configs(array $config): array {
    $pairs = is_array($config["share_pairs"] ?? null) ? array_values($config["share_pairs"]) : [];
    if (!$pairs) {
        return [["key" => "__default", "config" => $config]];
    }
    $configs = [];
    foreach ($pairs as $index => $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $pairConfig = $config;
        $pairConfig["server_a"] = array_replace($config["server_a"] ?? [], is_array($pair["server_a"] ?? null) ? $pair["server_a"] : []);
        $pairConfig["server_b"] = array_replace($config["server_b"] ?? [], is_array($pair["server_b"] ?? null) ? $pair["server_b"] : []);
        if (isset($pair["authority"])) {
            $pairConfig["authority"] = $pair["authority"];
        }
        if (isset($pair["delete_behavior"])) {
            $pairConfig["delete_behavior"] = $pair["delete_behavior"];
            $pairConfig["delete_propagation"] = $pair["delete_behavior"] === "mirror_deletes";
        } elseif (isset($pair["delete_propagation"])) {
            $pairConfig["delete_propagation"] = !empty($pair["delete_propagation"]);
        }
        if (isset($pair["name"])) {
            $pairConfig["name"] = $pair["name"];
        }
        $pairConfig["paused"] = !empty($pair["paused"]);
        $configs[] = ["key" => pair_key($pairConfig), "config" => $pairConfig];
    }
    return $configs ?: [["key" => "__default", "config" => $config]];
}

function load_scoped_state(string $configPath, string $stateKey): array {
    $allState = load_state($configPath);
    if ($stateKey === "__default") {
        return [
            "all" => $allState,
            "current" => [
                "files" => $allState["files"],
                "conflicts" => $allState["conflicts"],
                "_meta" => is_array($allState["_meta"] ?? null) ? $allState["_meta"] : [],
            ],
        ];
    }
    $current = is_array($allState["pairs"][$stateKey] ?? null) ? $allState["pairs"][$stateKey] : [];
    $current["files"] = is_array($current["files"] ?? null) ? $current["files"] : [];
    $current["conflicts"] = is_array($current["conflicts"] ?? null) ? $current["conflicts"] : [];
    return ["all" => $allState, "current" => $current];
}

function save_scoped_state(string $configPath, string $stateKey, array $allState, array $state): void {
    if ($stateKey === "__default") {
        $allState["files"] = $state["files"];
        $allState["conflicts"] = $state["conflicts"];
        $allState["_meta"] = is_array($state["_meta"] ?? null) ? $state["_meta"] : [];
    } else {
        $allState["pairs"] = is_array($allState["pairs"] ?? null) ? $allState["pairs"] : [];
        $allState["pairs"][$stateKey] = $state;
    }
    save_state($configPath, $allState);
}

function sync_once_pair(string $configPath, array $config, string $stateKey): array {
    $aRoot = (string)$config["server_a"]["root"];
    $bRoot = (string)$config["server_b"]["root"];
    $stateScope = load_scoped_state($configPath, $stateKey);
    $allState = $stateScope["all"];
    $state = $stateScope["current"];
    log_event("route " . route_label($config) . ": scan starting local {$aRoot} -> local {$bRoot}");
    mark_progress($state, "scanning", 0, 0);
    save_scoped_state($configPath, $stateKey, $allState, $state);
    $scanA = scan_files($aRoot);
    $scanB = scan_files($bRoot);
    $paths = array_unique(array_merge(array_keys($scanA), array_keys($scanB), array_keys($state["files"])));
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    $summary = empty_summary();
    $totalPaths = count($paths);
    $processedPaths = 0;
    $lastCheckpointAt = microtime(true);
    log_event("route " . route_label($config) . ": scan found server-a=" . count($scanA) . " server-b=" . count($scanB) . " indexed=" . count($state["files"]) . " total={$totalPaths}");
    mark_progress($state, "syncing", $processedPaths, $totalPaths);
    save_scoped_state($configPath, $stateKey, $allState, $state);

    foreach ($paths as $rel) {
        try {
            $a = $scanA[$rel] ?? ["exists" => false, "sig" => null];
            $b = $scanB[$rel] ?? ["exists" => false, "sig" => null];
            $prev = $state["files"][$rel] ?? null;
            $prevA = $prev["a_sig"] ?? null;
            $prevB = $prev["b_sig"] ?? null;
            $aChanged = $a["sig"] !== $prevA;
            $bChanged = $b["sig"] !== $prevB;

            if ($prev && ($prev["status"] ?? "") === "conflict" && !$aChanged && !$bChanged) {
                $summary["unchanged"]++;
                continue;
            }
            if ($a["exists"] && $b["exists"] && $a["sig"] === $b["sig"]) {
                record_file($state, $rel, $aRoot, $bRoot, "synced");
                $summary["unchanged"]++;
                continue;
            }
            if (!$prev) {
                if ($a["exists"] && !$b["exists"]) {
                    log_route_action($config, "copy server-a to server-b", $rel, "new on server-a");
                    copy_file($config, "server-a", $aRoot, "server-b", $bRoot, $rel, $summary);
                    record_file($state, $rel, $aRoot, $bRoot, "synced");
                } elseif ($b["exists"] && !$a["exists"]) {
                    log_route_action($config, "copy server-b to server-a", $rel, "new on server-b");
                    copy_file($config, "server-b", $bRoot, "server-a", $aRoot, $rel, $summary);
                    record_file($state, $rel, $aRoot, $bRoot, "synced");
                } elseif ($a["exists"] && $b["exists"]) {
                    log_route_action($config, "conflict", $rel, "new path differs on both servers");
                    record_conflict($state, $rel, "new_path_differs_on_both_servers", $aRoot, $bRoot, $summary);
                }
                continue;
            }
            if ($a["exists"] && $b["exists"]) {
                if ($aChanged && !$bChanged) {
                    log_route_action($config, "copy server-a to server-b", $rel, "server-a changed");
                    copy_file($config, "server-a", $aRoot, "server-b", $bRoot, $rel, $summary);
                    record_file($state, $rel, $aRoot, $bRoot, "synced");
                } elseif ($bChanged && !$aChanged) {
                    log_route_action($config, "copy server-b to server-a", $rel, "server-b changed");
                    copy_file($config, "server-b", $bRoot, "server-a", $aRoot, $rel, $summary);
                    record_file($state, $rel, $aRoot, $bRoot, "synced");
                } elseif ($aChanged && $bChanged) {
                    log_route_action($config, "conflict", $rel, "both changed");
                    record_conflict($state, $rel, "both_changed", $aRoot, $bRoot, $summary);
                } else {
                    log_route_action($config, "conflict", $rel, "state mismatch without change");
                    record_conflict($state, $rel, "state_mismatch_without_change", $aRoot, $bRoot, $summary);
                }
            } elseif ($a["exists"] && !$b["exists"]) {
                if (!$aChanged && equal_peer_delete_enabled($config)) {
                    log_route_action($config, "delete server-a", $rel, "equal peer delete mirrored");
                    delete_file($config, "server-a", $aRoot, $rel, $summary);
                    record_file($state, $rel, $aRoot, $bRoot, "deleted");
                } else {
                    log_route_action($config, "copy server-a to server-b", $rel, "missing on server-b");
                    copy_file($config, "server-a", $aRoot, "server-b", $bRoot, $rel, $summary);
                    record_file($state, $rel, $aRoot, $bRoot, "synced");
                }
            } elseif ($b["exists"] && !$a["exists"]) {
                if ($bChanged) {
                    log_route_action($config, "conflict", $rel, "server-a deleted and server-b changed");
                    record_conflict($state, $rel, "server_a_deleted_server_b_changed", $aRoot, $bRoot, $summary);
                } elseif (delete_mirroring_enabled($config)) {
                    log_route_action($config, "delete server-b", $rel, "delete mirrored");
                    delete_file($config, "server-b", $bRoot, $rel, $summary);
                    record_file($state, $rel, $aRoot, $bRoot, "deleted");
                } else {
                    log_route_action($config, "copy server-b to server-a", $rel, "missing on server-a");
                    copy_file($config, "server-b", $bRoot, "server-a", $aRoot, $rel, $summary);
                    record_file($state, $rel, $aRoot, $bRoot, "synced");
                }
            } else {
                record_file($state, $rel, $aRoot, $bRoot, "deleted");
                $summary["unchanged"]++;
            }
        } finally {
            $processedPaths++;
            if (should_checkpoint_progress($processedPaths, $totalPaths, $lastCheckpointAt)) {
                mark_progress($state, "syncing", $processedPaths, $totalPaths, $rel);
                save_scoped_state($configPath, $stateKey, $allState, $state);
                log_route_progress($config, $processedPaths, $totalPaths, $rel);
            }
        }
    }

    mark_progress($state, "complete", $totalPaths, $totalPaths);
    save_scoped_state($configPath, $stateKey, $allState, $state);
    log_event("route " . route_label($config) . ": complete indexed={$totalPaths} copied={$summary["copied"]} deleted={$summary["deleted"]} trashed={$summary["trashed"]} conflicts={$summary["conflicts"]} unchanged={$summary["unchanged"]}");
    return $summary;
}

function sync_once(string $configPath): array {
    $config = load_config($configPath);
    if (node_is_managed_remote($config)) {
        return empty_summary();
    }
    $summary = empty_summary();
    foreach (configured_pair_configs($config) as $pairConfig) {
        if (!empty($pairConfig["config"]["paused"])) {
            log_event("route " . route_label($pairConfig["config"]) . ": paused, skipping sync");
            continue;
        }
        try {
            $pairSummary = endpoint_is_remote($pairConfig["config"])
                ? sync_once_remote($configPath, $pairConfig["config"], $pairConfig["key"])
                : sync_once_pair($configPath, $pairConfig["config"], $pairConfig["key"]);
        } catch (MirrorPeerUnavailable $peerError) {
            mark_route_offline($configPath, $pairConfig["key"], $pairConfig["config"], $peerError->getMessage());
            continue;
        }
        add_summary($summary, $pairSummary);
    }
    return $summary;
}

function mark_route_offline(string $configPath, string $stateKey, array $config, string $message): void {
    $stateScope = load_scoped_state($configPath, $stateKey);
    $allState = $stateScope["all"];
    $state = $stateScope["current"];
    mark_progress($state, "peer_offline", 0, 0, $message);
    save_scoped_state($configPath, $stateKey, $allState, $state);
    log_event("route " . route_label($config) . ": peer offline, skipping sync - " . $message);
}

function sync_once_remote(string $configPath, array $config, string $stateKey = "__default"): array {
    $aRoot = (string)$config["server_a"]["root"];
    $bRoot = (string)$config["server_b"]["root"];
    $stateScope = load_scoped_state($configPath, $stateKey);
    $allState = $stateScope["all"];
    $state = $stateScope["current"];
    log_event("route " . route_label($config) . ": scan starting local {$aRoot} -> remote {$bRoot}");
    mark_progress($state, "scanning", 0, 0);
    save_scoped_state($configPath, $stateKey, $allState, $state);
    $scanA = scan_files($aRoot);
    $scanB = scan_remote_files($config, $configPath, $bRoot);
    $paths = array_unique(array_merge(array_keys($scanA), array_keys($scanB), array_keys($state["files"])));
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    $summary = empty_summary();
    $totalPaths = count($paths);
    $processedPaths = 0;
    $lastCheckpointAt = microtime(true);
    log_event("route " . route_label($config) . ": scan found local=" . count($scanA) . " remote=" . count($scanB) . " indexed=" . count($state["files"]) . " total={$totalPaths}");
    mark_progress($state, "syncing", $processedPaths, $totalPaths);
    save_scoped_state($configPath, $stateKey, $allState, $state);

    foreach ($paths as $rel) {
        try {
            $a = $scanA[$rel] ?? ["exists" => false, "sig" => null];
            $b = $scanB[$rel] ?? ["exists" => false, "sig" => null];
            $prev = $state["files"][$rel] ?? null;
            $prevA = $prev["a_sig"] ?? null;
            $prevB = $prev["b_sig"] ?? null;
            $aChanged = $a["sig"] !== $prevA;
            $bChanged = $b["sig"] !== $prevB;

            if ($prev && ($prev["status"] ?? "") === "conflict" && !$aChanged && !$bChanged) {
                $summary["unchanged"]++;
                continue;
            }
            if ($a["exists"] && $b["exists"] && $a["sig"] === $b["sig"]) {
                record_file_states($state, $rel, $a, $b, "synced");
                $summary["unchanged"]++;
                continue;
            }
            if (!$prev) {
                if ($a["exists"] && !$b["exists"]) {
                    log_route_action($config, "copy local to remote", $rel, "new locally");
                    copy_local_to_remote($config, $configPath, $aRoot, $bRoot, $rel, $summary);
                    record_file_states($state, $rel, $a, $a, "synced");
                } elseif ($b["exists"] && !$a["exists"]) {
                    log_route_action($config, "copy remote to local", $rel, "new remotely");
                    copy_remote_to_local($config, $configPath, $bRoot, $aRoot, $rel, $summary);
                    record_file_states($state, $rel, $b, $b, "synced");
                } elseif ($a["exists"] && $b["exists"]) {
                    log_route_action($config, "conflict", $rel, "new path differs on both servers");
                    record_conflict_states($state, $rel, "new_path_differs_on_both_servers", $a, $b, $summary);
                }
                continue;
            }
            if ($a["exists"] && $b["exists"]) {
                if ($aChanged && !$bChanged) {
                    log_route_action($config, "copy local to remote", $rel, "local changed");
                    trash_remote_file($config, $configPath, $bRoot, $rel, "overwritten", $summary);
                    copy_local_to_remote($config, $configPath, $aRoot, $bRoot, $rel, $summary);
                    record_file_states($state, $rel, $a, $a, "synced");
                } elseif ($bChanged && !$aChanged) {
                    log_route_action($config, "copy remote to local", $rel, "remote changed");
                    copy_remote_to_local($config, $configPath, $bRoot, $aRoot, $rel, $summary);
                    record_file_states($state, $rel, $b, $b, "synced");
                } elseif ($aChanged && $bChanged) {
                    log_route_action($config, "conflict", $rel, "both changed");
                    record_conflict_states($state, $rel, "both_changed", $a, $b, $summary);
                } else {
                    log_route_action($config, "conflict", $rel, "state mismatch without change");
                    record_conflict_states($state, $rel, "state_mismatch_without_change", $a, $b, $summary);
                }
            } elseif ($a["exists"] && !$b["exists"]) {
                if (!$aChanged && equal_peer_delete_enabled($config)) {
                    log_route_action($config, "delete local", $rel, "equal peer delete mirrored");
                    delete_file($config, "server-a", $aRoot, $rel, $summary);
                    record_file_states($state, $rel, ["exists" => false, "sig" => null], ["exists" => false, "sig" => null], "deleted");
                } else {
                    log_route_action($config, "copy local to remote", $rel, "missing remotely");
                    copy_local_to_remote($config, $configPath, $aRoot, $bRoot, $rel, $summary);
                    record_file_states($state, $rel, $a, $a, "synced");
                }
            } elseif ($b["exists"] && !$a["exists"]) {
                if ($bChanged) {
                    log_route_action($config, "conflict", $rel, "local deleted and remote changed");
                    record_conflict_states($state, $rel, "server_a_deleted_server_b_changed", $a, $b, $summary);
                } elseif (delete_mirroring_enabled($config)) {
                    log_route_action($config, "delete remote", $rel, "delete mirrored");
                    delete_remote_file($config, $configPath, $bRoot, $rel, $summary);
                    record_file_states($state, $rel, ["exists" => false, "sig" => null], ["exists" => false, "sig" => null], "deleted");
                } else {
                    log_route_action($config, "copy remote to local", $rel, "missing locally");
                    copy_remote_to_local($config, $configPath, $bRoot, $aRoot, $rel, $summary);
                    record_file_states($state, $rel, $b, $b, "synced");
                }
            } else {
                record_file_states($state, $rel, $a, $b, "deleted");
                $summary["unchanged"]++;
            }
        } finally {
            $processedPaths++;
            if (should_checkpoint_progress($processedPaths, $totalPaths, $lastCheckpointAt)) {
                mark_progress($state, "syncing", $processedPaths, $totalPaths, $rel);
                save_scoped_state($configPath, $stateKey, $allState, $state);
                log_route_progress($config, $processedPaths, $totalPaths, $rel);
            }
        }
    }

    mark_progress($state, "complete", $totalPaths, $totalPaths);
    save_scoped_state($configPath, $stateKey, $allState, $state);
    log_event("route " . route_label($config) . ": complete indexed={$totalPaths} copied={$summary["copied"]} deleted={$summary["deleted"]} trashed={$summary["trashed"]} conflicts={$summary["conflicts"]} unchanged={$summary["unchanged"]}");
    return $summary;
}

function test_peer(string $configPath): string {
    $config = load_config($configPath);
    if (!endpoint_is_remote($config)) {
        return "Server B location is set to same-server local mode. Switch to LAN peer mode and save settings first.";
    }
    $root = (string)$config["server_b"]["root"];
    $script = "echo connected && test -d " . escapeshellarg($root) . " && echo share-ok || echo share-missing";
    return run_command(array_merge(ssh_base_args($config, $configPath), [ssh_target($config), $script]));
}

$args = $argv;
array_shift($args);
$command = array_shift($args);
if (!$command) {
    exit(usage());
}

$configPath = option_value($args, "--config", "/boot/config/plugins/mirror/config.json");

try {
    if ($command === "interval") {
        echo safe_interval(load_config((string)$configPath)) . "\n";
        exit(0);
    }
    if ($command === "run-once") {
        $summary = sync_once((string)$configPath);
        echo "sync complete: copied={$summary["copied"]} deleted={$summary["deleted"]} trashed={$summary["trashed"]} conflicts={$summary["conflicts"]} unchanged={$summary["unchanged"]}\n";
        exit($summary["conflicts"] > 0 ? 1 : 0);
    }
    if ($command === "initial-sync") {
        $summary = initial_sync((string)$configPath);
        echo "initial sync complete: copied={$summary["copied"]} deleted={$summary["deleted"]} trashed={$summary["trashed"]} conflicts={$summary["conflicts"]} unchanged={$summary["unchanged"]}\n";
        exit($summary["conflicts"] > 0 ? 1 : 0);
    }
    if ($command === "test-peer") {
        echo test_peer((string)$configPath) . "\n";
        exit(0);
    }
    if ($command === "daemon") {
        $interval = max(1, min(3600, (int)option_value($args, "--interval", "10")));
        echo "mirror daemon started: interval={$interval}s\n";
        while (true) {
            try {
                $summary = sync_once((string)$configPath);
                echo date("c") . " sync complete: copied={$summary["copied"]} deleted={$summary["deleted"]} trashed={$summary["trashed"]} conflicts={$summary["conflicts"]} unchanged={$summary["unchanged"]}\n";
            } catch (Throwable $syncError) {
                echo date("c") . " sync error: " . $syncError->getMessage() . "\n";
            }
            flush();
            sleep($interval);
        }
    }
    exit(usage());
} catch (Throwable $error) {
    fwrite(STDERR, "Mirror error: " . $error->getMessage() . "\n");
    exit(1);
}
