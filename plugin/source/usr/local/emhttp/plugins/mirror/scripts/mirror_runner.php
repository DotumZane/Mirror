<?php
declare(strict_types=1);

function usage(): int {
    fwrite(STDERR, "Usage: mirror_runner.php {interval|run-once|daemon|test-peer} --config <path> [--interval <seconds>]\n");
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
    return max(1, min(3600, (int)($config["sync_interval"] ?? 10)));
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

function ssh_key_path(string $configPath): string {
    return dirname($configPath) . "/ssh/mirror_ed25519";
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
    $key = ssh_key_path($configPath);
    if (!is_file($key)) {
        throw new RuntimeException("SSH key is missing. Generate a key from the Mirror settings page first.");
    }
    return [
        "ssh",
        "-i", $key,
        "-p", (string)$port,
        "-o", "BatchMode=yes",
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

function scan_files(string $root): array {
    $files = [];
    if (!is_dir($root)) {
        mkdir($root, 0777, true);
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

function scan_remote_files(array $config, string $configPath, string $root): array {
    $ssh = ssh_base_args($config, $configPath);
    $target = ssh_target($config);
    $script = "mkdir -p " . escapeshellarg($root) . " && find " . escapeshellarg($root) . " -type f -printf '%P\\t%s\\t%T@\\n'";
    $output = run_command(array_merge($ssh, [$target, $script]));
    $files = [];
    foreach (explode("\n", trim($output)) as $line) {
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

function sync_once(string $configPath): array {
    $config = load_config($configPath);
    if (endpoint_is_remote($config)) {
        return sync_once_remote($configPath, $config);
    }
    $aRoot = (string)$config["server_a"]["root"];
    $bRoot = (string)$config["server_b"]["root"];
    $state = load_state($configPath);
    $scanA = scan_files($aRoot);
    $scanB = scan_files($bRoot);
    $paths = array_unique(array_merge(array_keys($scanA), array_keys($scanB), array_keys($state["files"])));
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    $summary = ["copied" => 0, "deleted" => 0, "trashed" => 0, "conflicts" => 0, "unchanged" => 0];

    foreach ($paths as $rel) {
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
                copy_file($config, "server-a", $aRoot, "server-b", $bRoot, $rel, $summary);
                record_file($state, $rel, $aRoot, $bRoot, "synced");
            } elseif ($b["exists"] && !$a["exists"]) {
                copy_file($config, "server-b", $bRoot, "server-a", $aRoot, $rel, $summary);
                record_file($state, $rel, $aRoot, $bRoot, "synced");
            } elseif ($a["exists"] && $b["exists"]) {
                record_conflict($state, $rel, "new_path_differs_on_both_servers", $aRoot, $bRoot, $summary);
            }
            continue;
        }
        if ($a["exists"] && $b["exists"]) {
            if ($aChanged && !$bChanged) {
                copy_file($config, "server-a", $aRoot, "server-b", $bRoot, $rel, $summary);
                record_file($state, $rel, $aRoot, $bRoot, "synced");
            } elseif ($bChanged && !$aChanged) {
                copy_file($config, "server-b", $bRoot, "server-a", $aRoot, $rel, $summary);
                record_file($state, $rel, $aRoot, $bRoot, "synced");
            } elseif ($aChanged && $bChanged) {
                record_conflict($state, $rel, "both_changed", $aRoot, $bRoot, $summary);
            } else {
                record_conflict($state, $rel, "state_mismatch_without_change", $aRoot, $bRoot, $summary);
            }
        } elseif ($a["exists"] && !$b["exists"]) {
            if (!$aChanged && equal_peer_delete_enabled($config)) {
                delete_file($config, "server-a", $aRoot, $rel, $summary);
                record_file($state, $rel, $aRoot, $bRoot, "deleted");
            } else {
                copy_file($config, "server-a", $aRoot, "server-b", $bRoot, $rel, $summary);
                record_file($state, $rel, $aRoot, $bRoot, "synced");
            }
        } elseif ($b["exists"] && !$a["exists"]) {
            if ($bChanged) {
                record_conflict($state, $rel, "server_a_deleted_server_b_changed", $aRoot, $bRoot, $summary);
            } elseif (delete_mirroring_enabled($config)) {
                delete_file($config, "server-b", $bRoot, $rel, $summary);
                record_file($state, $rel, $aRoot, $bRoot, "deleted");
            } else {
                copy_file($config, "server-b", $bRoot, "server-a", $aRoot, $rel, $summary);
                record_file($state, $rel, $aRoot, $bRoot, "synced");
            }
        } else {
            record_file($state, $rel, $aRoot, $bRoot, "deleted");
            $summary["unchanged"]++;
        }
    }

    save_state($configPath, $state);
    return $summary;
}

function sync_once_remote(string $configPath, array $config): array {
    $aRoot = (string)$config["server_a"]["root"];
    $bRoot = (string)$config["server_b"]["root"];
    $state = load_state($configPath);
    $scanA = scan_files($aRoot);
    $scanB = scan_remote_files($config, $configPath, $bRoot);
    $paths = array_unique(array_merge(array_keys($scanA), array_keys($scanB), array_keys($state["files"])));
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    $summary = ["copied" => 0, "deleted" => 0, "trashed" => 0, "conflicts" => 0, "unchanged" => 0];

    foreach ($paths as $rel) {
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
                copy_local_to_remote($config, $configPath, $aRoot, $bRoot, $rel, $summary);
                record_file_states($state, $rel, $a, $a, "synced");
            } elseif ($b["exists"] && !$a["exists"]) {
                copy_remote_to_local($config, $configPath, $bRoot, $aRoot, $rel, $summary);
                record_file_states($state, $rel, $b, $b, "synced");
            } elseif ($a["exists"] && $b["exists"]) {
                record_conflict_states($state, $rel, "new_path_differs_on_both_servers", $a, $b, $summary);
            }
            continue;
        }
        if ($a["exists"] && $b["exists"]) {
            if ($aChanged && !$bChanged) {
                trash_remote_file($config, $configPath, $bRoot, $rel, "overwritten", $summary);
                copy_local_to_remote($config, $configPath, $aRoot, $bRoot, $rel, $summary);
                record_file_states($state, $rel, $a, $a, "synced");
            } elseif ($bChanged && !$aChanged) {
                copy_remote_to_local($config, $configPath, $bRoot, $aRoot, $rel, $summary);
                record_file_states($state, $rel, $b, $b, "synced");
            } elseif ($aChanged && $bChanged) {
                record_conflict_states($state, $rel, "both_changed", $a, $b, $summary);
            } else {
                record_conflict_states($state, $rel, "state_mismatch_without_change", $a, $b, $summary);
            }
        } elseif ($a["exists"] && !$b["exists"]) {
            if (!$aChanged && equal_peer_delete_enabled($config)) {
                delete_file($config, "server-a", $aRoot, $rel, $summary);
                record_file_states($state, $rel, ["exists" => false, "sig" => null], ["exists" => false, "sig" => null], "deleted");
            } else {
                copy_local_to_remote($config, $configPath, $aRoot, $bRoot, $rel, $summary);
                record_file_states($state, $rel, $a, $a, "synced");
            }
        } elseif ($b["exists"] && !$a["exists"]) {
            if ($bChanged) {
                record_conflict_states($state, $rel, "server_a_deleted_server_b_changed", $a, $b, $summary);
            } elseif (delete_mirroring_enabled($config)) {
                delete_remote_file($config, $configPath, $bRoot, $rel, $summary);
                record_file_states($state, $rel, ["exists" => false, "sig" => null], ["exists" => false, "sig" => null], "deleted");
            } else {
                copy_remote_to_local($config, $configPath, $bRoot, $aRoot, $rel, $summary);
                record_file_states($state, $rel, $b, $b, "synced");
            }
        } else {
            record_file_states($state, $rel, $a, $b, "deleted");
            $summary["unchanged"]++;
        }
    }

    save_state($configPath, $state);
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
    if ($command === "test-peer") {
        echo test_peer((string)$configPath) . "\n";
        exit(0);
    }
    if ($command === "daemon") {
        $interval = max(1, min(3600, (int)option_value($args, "--interval", "10")));
        echo "mirror daemon started: interval={$interval}s\n";
        while (true) {
            $summary = sync_once((string)$configPath);
            echo date("c") . " sync complete: copied={$summary["copied"]} deleted={$summary["deleted"]} trashed={$summary["trashed"]} conflicts={$summary["conflicts"]} unchanged={$summary["unchanged"]}\n";
            sleep($interval);
        }
    }
    exit(usage());
} catch (Throwable $error) {
    fwrite(STDERR, "Mirror error: " . $error->getMessage() . "\n");
    exit(1);
}
