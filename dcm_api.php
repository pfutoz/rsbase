<?php
// ==============================================================================
// RS-Base 高速ダイレクトDICOMストリーミングAPI (dcm_api.php)
// 内視鏡JPEGダイレクト抽出 (30ms) ＋ 非圧縮(US/XP)自動変換 & キャッシュ
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

$cache_dir = __DIR__ . '/tmp/dcm_cache/';
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
     * @param int $max_total_mb 上限容量MB (デフォルト: 1000MB)
     */
    function purge_expired_cache($dir, $max_age_seconds = 604800, $max_total_mb = 1000) {
        // サーバー負荷ゼロ化のため、リクエストの約2% (50回に1回) のみ確率的に実行
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
                // 有効期限切れ（7日以上経過）ファイルを即時削除
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

        // 容量上限を超過している場合は、古いファイルから順に80%の容量まで削除 (LRU)
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

// 7日間経過または1GB超過キャッシュの自動ガベージコレクション (約50リクエストに1回の確率で低負荷実行)
purge_expired_cache($cache_dir, 7 * 86400, 1000);

// フォルダパスの正規化
$raw_folder = $_GET['folder'] ?? $_POST['folder'] ?? '';
$folder = rtrim(trim(str_replace('/', '\\', $raw_folder)), '\\');

// アクション判定
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ① ファイル一覧の取得 (action=list)
if ($action === 'list') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');

    if (empty($folder) || !is_dir($folder)) {
        echo json_encode([
            'status' => 'error',
            'message' => '指定されたDICOMフォルダが存在しないか、アクセスできません: ' . $folder
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $dir_handle = @opendir($folder);
        $files = [];

        if ($dir_handle) {
            while (($file = readdir($dir_handle)) !== false) {
                if ($file === '.' || $file === '..') continue;
                $full_path = $folder . '\\' . $file;
                if (is_dir($full_path)) continue;

                // 拡張子が .dcm または拡張子なしの検査ファイル
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ($ext === 'dcm' || empty($ext) || $ext === 'ecg') {
                    $files[] = [
                        'filename' => $file,
                        'size'     => @filesize($full_path) ?: 0,
                        'mtime'    => @filemtime($full_path) ?: 0
                    ];
                }
            }
            closedir($dir_handle);
        }

        // 自然順ソート (001.dcm, 002.dcm, 003.dcm...)
        usort($files, function($a, $b) {
            return strnatcasecmp($a['filename'], $b['filename']);
        });

        echo json_encode([
            'status' => 'success',
            'folder' => $folder,
            'total'  => count($files),
            'files'  => $files
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'フォルダ読み込みエラー: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ② 胃がん検診用 JPEG一括ZIPダウンロード (action=zip)
if ($action === 'zip') {
    ob_clean();
    $target_files = $_POST['files'] ?? [];
    if (empty($target_files) && isset($_GET['files'])) {
        $target_files = explode(',', $_GET['files']);
    }

    if (empty($folder) || !is_dir($folder)) {
        header('HTTP/1.1 400 Bad Request');
        echo "フォルダが存在しません";
        exit;
    }

    $zip_filename = basename(rtrim($folder, '\\/')) . '_JPEG.zip';
    $tmp_zip_path = $cache_dir . 'download_' . md5($folder . time()) . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($tmp_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        header('HTTP/1.1 500 Internal Server Error');
        echo "ZIPファイルの作成に失敗しました";
        exit;
    }

    // ファイル指定がなければフォルダ内の全DCMを対象
    if (empty($target_files)) {
        $scan = scandir($folder);
        foreach ($scan as $f) {
            if ($f === '.' || $f === '..') continue;
            if (is_file($folder . '\\' . $f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'dcm') {
                $target_files[] = $f;
            }
        }
    }

    foreach ($target_files as $f) {
        $dcm_path = $folder . '\\' . basename($f);
        if (file_exists($dcm_path)) {
            $jpeg_data = get_dcm_jpeg_bytes($dcm_path, false, $cache_dir);
            if ($jpeg_data !== null) {
                $base = pathinfo($f, PATHINFO_FILENAME);
                $zip->addFromString($base . '.jpg', $jpeg_data);
            }
        }
    }

    $zip->close();

    if (file_exists($tmp_zip_path)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($tmp_zip_path));
        header('Pragma: no-cache');
        readfile($tmp_zip_path);
        @unlink($tmp_zip_path);
        exit;
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo "ZIPファイルの生成に失敗しました";
        exit;
    }
}

// ③ 画像ストリーミング配信 (action未指定 または action=image)
$file = $_GET['file'] ?? '';
$is_thumb = isset($_GET['thumb']) && $_GET['thumb'] === '1';

if (empty($folder) || empty($file)) {
    ob_clean();
    header('HTTP/1.1 400 Bad Request');
    echo "パラメータが不足しています";
    exit;
}

$full_path = $folder . '\\' . basename($file);
if (!file_exists($full_path)) {
    ob_clean();
    header('HTTP/1.1 404 Not Found');
    echo "DICOMファイルが見つかりません: " . htmlspecialchars($full_path);
    exit;
}

$jpeg_data = get_dcm_jpeg_bytes($full_path, $is_thumb, $cache_dir);

if ($jpeg_data !== null) {
    ob_clean();
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($jpeg_data));
    header('Cache-Control: public, max-age=86400'); // 1日キャッシュ
    echo $jpeg_data;
    exit;
} else {
    ob_clean();
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200" viewBox="0 0 300 200"><rect fill="#1e293b" width="100%" height="100%"/><text fill="#94a3b8" x="50%" y="50%" text-anchor="middle" font-size="14">画像取得エラー</text></svg>';
    exit;
}

// ==============================================================================
// 内部関数: 高速JPEG抽出 & キャッシュ機構
// ==============================================================================
function get_dcm_jpeg_bytes($dcm_path, $is_thumb, $cache_dir) {
    $mtime = @filemtime($dcm_path) ?: time();
    $cache_key = md5($dcm_path . '_' . $mtime . ($is_thumb ? '_thumb' : '_full'));
    $cache_file = $cache_dir . $cache_key . '.jpg';

    // 1. キャッシュが存在すれば検証して返却 (破損/0バイトキャッシュは自動破棄)
    if (file_exists($cache_file)) {
        $sz = @filesize($cache_file);
        if ($sz !== false && $sz > 100) {
            $cached = @file_get_contents($cache_file);
            if ($cached !== false && strlen($cached) > 100 && substr($cached, 0, 2) === "\xFF\xD8") {
                return $cached;
            }
        }
        @unlink($cache_file);
    }

    // 2. 内視鏡など：埋め込みJPEGのダイレクト抽出 (30ms)
    try {
        $handle = @fopen($dcm_path, 'rb');
        if ($handle) {
            $content = stream_get_contents($handle);
            fclose($handle);

            $soi = strpos($content, "\xFF\xD8\xFF");
            if ($soi !== false) {
                $eoi = strrpos($content, "\xFF\xD9");
                if ($eoi !== false && $eoi > $soi) {
                    $raw_jpeg = substr($content, $soi, $eoi - $soi + 2);

                    if ($is_thumb) {
                        $img = @imagecreatefromstring($raw_jpeg);
                        if ($img !== false) {
                            $w = imagesx($img);
                            $h = imagesy($img);
                            $tw = 200;
                            $th = max(1, (int)($h * ($tw / max(1, $w))));
                            $thumb_img = imagecreatetruecolor($tw, $th);
                            imagecopyresampled($thumb_img, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
                            ob_start();
                            imagejpeg($thumb_img, null, 80);
                            $thumb_jpeg = ob_get_clean();
                            imagedestroy($thumb_img);
                            imagedestroy($img);
                            atomic_write_file($cache_file, $thumb_jpeg);
                            return $thumb_jpeg;
                        }
                    }

                    atomic_write_file($cache_file, $raw_jpeg);
                    return $raw_jpeg;
                }
            }
        }
    } catch (Throwable $e) {
        error_log("dcm_api extract error: " . $e->getMessage());
    }

    // 3. 非圧縮DICOM (US, レントゲン等)：Python/Pillow フォールバック (約200ms)
    try {
        $py_script = __DIR__ . '/dcm_render.py';
        if (file_exists($py_script)) {
            $py_bin = find_python_bin();
            $thumb_arg = $is_thumb ? ' thumb' : '';
            $temp_render = $cache_file . '.' . uniqid('py_', true) . '.jpg';
            $cmd = escapeshellarg($py_bin) . ' ' . escapeshellarg($py_script) . ' ' . escapeshellarg($dcm_path) . ' ' . escapeshellarg($temp_render) . $thumb_arg . ' 2>&1';
            exec($cmd, $out, $ret);
            if ($ret === 0 && file_exists($temp_render) && filesize($temp_render) > 100) {
                @rename($temp_render, $cache_file);
                return @file_get_contents($cache_file);
            }
            if (file_exists($temp_render)) {
                @unlink($temp_render);
            }
            if ($ret !== 0) {
                error_log("dcm_render.py error (ret=$ret): " . implode("\n", $out));
            }
        }
    } catch (Throwable $e) {
        error_log("dcm_api python fallback error: " . $e->getMessage());
    }

    return null;
}

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
