<?php
// ==============================================================================
// RS-Base 心電図データ解析API (ecg_api.php)
// フクダ電子 (12L anal + wave) 解析・JSON返却 & キャッシュ機構
// ==============================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);

// 未捕捉致命的エラー時のJSONフェイルオーバー
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(500);
        }
        echo json_encode([
            'status' => 'error',
            'message' => 'API内部エラーが発生しました: ' . $err['message']
        ], JSON_UNESCAPED_UNICODE);
    }
});

ob_start();
header('Content-Type: application/json; charset=UTF-8');

$cache_dir = __DIR__ . '/tmp/ecg_cache/';
if (!file_exists($cache_dir)) {
    @mkdir($cache_dir, 0777, true);
}

if (!function_exists('atomic_write_file')) {
    /**
     * アトミック安全書き込み (一時ファイルに書いてからrename)
     */
    function atomic_write_file($path, $data) {
        $temp = $path . '.' . uniqid('tmp_', true);
        if (@file_put_contents($temp, $data) !== false) {
            if (@rename($temp, $path)) {
                return true;
            }
            @unlink($temp);
        }
        return false;
    }
}

if (!function_exists('purge_expired_cache')) {
    /**
     * キャッシュの自動ガベージコレクション
     * @param string $dir キャッシュディレクトリ
     * @param int $max_age_seconds 有効期間秒数 (デフォルト: 7日間 = 604,800秒)
     * @param int $max_total_mb 上限容量MB (デフォルト: 300MB)
     */
    function purge_expired_cache($dir, $max_age_seconds = 604800, $max_total_mb = 300) {
        if (mt_rand(1, 50) !== 1) {
            return;
        }
        if (!is_dir($dir)) return;

        $now = time();
        $files = [];
        $total_bytes = 0;

        $handle = @opendir($dir);
        if ($handle) {
            while (($file = readdir($handle)) !== false) {
                if ($file === '.' || $file === '..' || $file === '.gitkeep') continue;
                $path = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $file;
                if (!is_file($path)) continue;

                $mtime = @filemtime($path) ?: $now;
                if (($now - $mtime) > $max_age_seconds) {
                    @unlink($path);
                    continue;
                }

                $size = @filesize($path) ?: 0;
                $total_bytes += $size;
                $files[] = ['path' => $path, 'mtime' => $mtime, 'size' => $size];
            }
            closedir($handle);
        }

        $max_bytes = $max_total_mb * 1024 * 1024;
        if ($total_bytes > $max_bytes && !empty($files)) {
            usort($files, function($a, $b) {
                return $a['mtime'] <=> $b['mtime'];
            });
            foreach ($files as $f) {
                @unlink($f['path']);
                $total_bytes -= $f['size'];
                if ($total_bytes <= $max_bytes * 0.8) {
                    break;
                }
            }
        }
    }
}

// 7日間経過または300MB超過キャッシュの自動ガベージコレクション (約50リクエストに1回の確率で低負荷実行)
purge_expired_cache($cache_dir, 7 * 86400, 300);

$raw_file = $_GET['file'] ?? $_POST['file'] ?? '';
$file = str_replace('/', '\\', trim($raw_file));

if (empty($file)) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'ファイルパスが指定されていません'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!file_exists($file)) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => '心電図ファイルが見つかりません: ' . $file], JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_dir($file)) {
    $candidates = glob(rtrim($file, '\\/') . '/*.{ECG,ecg,001}', GLOB_BRACE);
    if (!empty($candidates)) {
        $file = $candidates[0];
    }
}

$mtime = @filemtime($file) ?: time();
$cache_file = $cache_dir . md5($file . '_' . $mtime) . '.json';

// キャッシュ検証 (破損または不正JSONは自動削除して再生成へフェイルオーバー)
if (file_exists($cache_file)) {
    $cached_raw = @file_get_contents($cache_file);
    if ($cached_raw !== false && strlen($cached_raw) > 100) {
        $test_json = @json_decode($cached_raw, true);
        if ($test_json && isset($test_json['status']) && $test_json['status'] === 'success' && isset($test_json['data']['leads'])) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            echo $cached_raw;
            exit;
        }
    }
    @unlink($cache_file);
}

$py_script = __DIR__ . '/ecg_render.py';
if (!file_exists($py_script)) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    echo json_encode(['status' => 'error', 'message' => 'ecg_render.py が見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$py_bin = find_python_bin();

$cmd = escapeshellarg($py_bin) . ' ' . escapeshellarg($py_script) . ' ' . escapeshellarg($file) . ' --json 2>&1';
exec($cmd, $output_lines, $ret);

if ($ret !== 0 || empty($output_lines)) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $err_msg = '心電図バイナリの解析に失敗しました';
    if (!empty($output_lines)) {
        error_log("ecg_render.py error (ret=$ret): " . implode("\n", $output_lines));
    }
    echo json_encode(['status' => 'error', 'message' => $err_msg, 'debug' => $output_lines], JSON_UNESCAPED_UNICODE);
    exit;
}

$json_str = implode("\n", $output_lines);
$parsed = @json_decode($json_str, true);

if (!$parsed || !isset($parsed['leads'])) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    echo json_encode(['status' => 'error', 'message' => '解析JSONのフォーマットが不正です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$response_data = [
    'status' => 'success',
    'file'   => $file,
    'data'   => $parsed
];

$final_json = json_encode($response_data, JSON_UNESCAPED_UNICODE);
atomic_write_file($cache_file, $final_json);

while (ob_get_level() > 0) {
    @ob_end_clean();
}
echo $final_json;
exit;

function find_python_bin() {
    $candidates = [
        'C:\\Users\\owner\\AppData\\Local\\Python\\bin\\python.exe',
        'C:\\Users\\owner\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe',
        'C:\\Users\\Owner\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
        'C:\\Users\\owner\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
        'C:\\Program Files\\Python314\\python.exe',
        'C:\\Program Files\\Python313\\python.exe',
        'C:\\Python314\\python.exe',
        'C:\\Python313\\python.exe'
    ];
    foreach ($candidates as $cand) {
        if (file_exists($cand)) {
            return $cand;
        }
    }
    $where = @shell_exec('where python 2>NUL');
    if ($where) {
        $lines = explode("\n", trim($where));
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && stripos($line, 'WindowsApps') === false && file_exists($line)) {
                return $line;
            }
        }
    }
    return 'python';
}
