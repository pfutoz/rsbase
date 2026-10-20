<?php
// ==============================================================================
// RS-Base 心電図データ解析API (ecg_api.php)
// フクダ電子 (12L anal + wave) 解析・JSON返却 & キャッシュ機構
// ==============================================================================

ob_start();
header('Content-Type: application/json; charset=UTF-8');

$cache_dir = __DIR__ . '/tmp/ecg_cache/';
if (!file_exists($cache_dir)) {
    @mkdir($cache_dir, 0777, true);
}

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

if (file_exists($cache_file) && filesize($cache_file) > 100) {
    ob_clean();
    echo file_get_contents($cache_file);
    exit;
}

$py_script = __DIR__ . '/ecg_render.py';
if (!file_exists($py_script)) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'ecg_render.py が見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$py_bin = find_python_bin();

$cmd = escapeshellarg($py_bin) . ' ' . escapeshellarg($py_script) . ' ' . escapeshellarg($file) . ' --json 2>&1';
exec($cmd, $output_lines, $ret);

if ($ret !== 0 || empty($output_lines)) {
    ob_clean();
    $err_msg = '心電図バイナリの解析に失敗しました';
    if (!empty($output_lines)) {
        error_log("ecg_render.py error (ret=$ret): " . implode("\n", $output_lines));
    }
    echo json_encode(['status' => 'error', 'message' => $err_msg, 'debug' => $output_lines], JSON_UNESCAPED_UNICODE);
    exit;
}

$json_str = implode("\n", $output_lines);
$parsed = json_decode($json_str, true);

if (!$parsed || !isset($parsed['leads'])) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => '解析JSONのフォーマットが不正です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$response_data = [
    'status' => 'success',
    'file'   => $file,
    'data'   => $parsed
];

$final_json = json_encode($response_data, JSON_UNESCAPED_UNICODE);
@file_put_contents($cache_file, $final_json);

ob_clean();
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
