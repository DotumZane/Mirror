<?php
declare(strict_types=1);

function usage(): int {
    fwrite(STDERR, "Usage: mirror_runner.php {interval|run-once|daemon} --config <path> [--interval <seconds>]\n");
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

function state_for(string $path): array {
    if (!is_file($path)) {
        return ["exists" => false, "sig" => null];
    }
    return ["exists" => true, "sig" => filesize($path) . ":" . filemtime($path)];
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

function record_conflict(array &$state, string $rel, string $reason, string $aRoot, string $bRoot, array &$summary): void {
    $state["conflicts"][] = [
        "path" => $rel,
        "reason" => $reason,
        "created_at" => time(),
    ];
    record_file($state, $rel, $aRoot, $bRoot, "conflict");
    $summary["conflicts"]++;
}

function sync_once(string $configPath): array {
    $config = load_config($configPath);
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
            copy_file($config, "server-a", $aRoot, "server-b", $bRoot, $rel, $summary);
            record_file($state, $rel, $aRoot, $bRoot, "synced");
        } elseif ($b["exists"] && !$a["exists"]) {
            if ($bChanged) {
                record_conflict($state, $rel, "server_a_deleted_server_b_changed", $aRoot, $bRoot, $summary);
            } elseif (!empty($config["delete_propagation"])) {
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
