<?php
declare(strict_types=1);

/** Small host-local throttle for unauthenticated account endpoints. */
function nbc_rate_limit(string $scope, int $limit, int $windowSeconds, bool $consume = false, bool $clear = false): int
{
    $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $safeScope = preg_replace('/[^a-z0-9_-]/i', '', $scope) ?: 'default';
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nbc_rate_limits';
    if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) return 0;
    @chmod($base, 0700);
    $path = $base . DIRECTORY_SEPARATOR . $safeScope . '_' . hash('sha256', $address) . '.json';
    $handle = @fopen($path, 'c+');
    if (!$handle) return 0;
    try {
        if (!flock($handle, LOCK_EX)) return 0;
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = json_decode($raw ?: '', true);
        $times = is_array($state) && is_array($state['times'] ?? null) ? $state['times'] : [];
        $now = time();
        $times = array_values(array_filter($times, static fn($at): bool => is_int($at) && $at > $now - $windowSeconds));
        if ($clear) {
            $times = [];
        } elseif ($consume) {
            if (count($times) >= $limit) return max(1, $windowSeconds - ($now - min($times)));
            $times[] = $now;
        }
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode(['times' => $times]));
        fflush($handle);
        return 0;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
