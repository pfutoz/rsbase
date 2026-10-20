<?php
// --- index.php (フル機能版 ＋ 年齢クリックは常にJPEG出力固定版) ---
$host = '192.168.1.164';
$dbname = 'gazouDB';
$user = 'postgres';
$password = 'medicalin';
$port = '5432';

// PHPから見たtmpディレクトリのパス
$tmp_dir = __DIR__ . '/tmp/';

// ① 一覧からの非同期起動リクエスト (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'launch') {
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');

    $folder = $_POST['folder'] ?? '';
    $mode   = $_POST['launch_mode'] ?? 'NORMAL'; // 'NORMAL', 'ECG', または 'JPEG'
    
    if (!empty($folder)) {
        // クライアントのIPアドレスを取得
        $client_ip = $_SERVER['REMOTE_ADDR'];
        
        // tmpディレクトリがない場合は作成を試みる
        if (!file_exists($tmp_dir)) {
            @mkdir($tmp_dir, 0777, true);
        }
        
        // IPアドレス別のトリガーファイルパス
        $client_trigger_file = $tmp_dir . 'trigger_' . $client_ip . '.txt';

        // モードとパスをパイプ区切りで書き込む
        $data_to_write = $mode . '|' . $folder;

        $result = @file_put_contents($client_trigger_file, $data_to_write);
        if ($result !== false) {
            ob_clean();
            echo json_encode(['status' => 'success']);
        } else {
            $error = error_get_last();
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'トリガーファイルの書き込み失敗: ' . ($error['message'] ?? 'パーミッションを確認してください')]);
        }
    } else {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'フォルダパスが空です。']);
    }
    exit;
}

// ② 患者検索リクエスト (AJAX - gazouDB横断検索)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_patient') {
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');

    $query = trim($_POST['query'] ?? '');
    if ($query === '') {
        ob_clean();
        echo json_encode(['status' => 'success', 'data' => []]);
        exit;
    }

    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $pdo->exec("SET client_encoding TO 'SHIFT_JIS'");

        // 数字・全角半角の正規化
        $num_normalized = mb_convert_kana($query, 'n', 'UTF-8');
        $stripped_id = preg_replace('/^0+([0-9]+)$/', '$1', trim($num_normalized));

        // カナ変換（ひらがな→全角カタカナ、全角カタカナ→半角カタカナ）
        $yomi_full = mb_convert_kana($query, 'KVC', 'UTF-8');
        $yomi_half = mb_convert_kana($yomi_full, 'k', 'UTF-8');

        // CP932変換
        $query_cp932 = mb_convert_encoding($query, 'CP932', 'UTF-8');
        $stripped_cp932 = mb_convert_encoding($stripped_id, 'CP932', 'UTF-8');
        $num_cp932 = mb_convert_encoding($num_normalized, 'CP932', 'UTF-8');
        $yomi_full_cp932 = mb_convert_encoding($yomi_full, 'CP932', 'UTF-8');
        $yomi_half_cp932 = mb_convert_encoding($yomi_half, 'CP932', 'UTF-8');

        // gazou_tbl から患者一覧を抽出（最新検査日・件数付き）
        $sql = "SELECT id, kanja_kanji, kanja_yomi, MAX(date) AS latest_date, COUNT(*) AS exam_count 
                FROM gazou_tbl 
                WHERE id = ? 
                   OR id = ? 
                   OR id = ? 
                   OR id LIKE ? 
                   OR kanja_kanji LIKE ? 
                   OR kanja_yomi LIKE ? 
                   OR kanja_yomi LIKE ? 
                GROUP BY id, kanja_kanji, kanja_yomi 
                ORDER BY latest_date DESC, id ASC 
                LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $stripped_cp932,
            $num_cp932,
            $query_cp932,
            '%' . $stripped_cp932 . '%',
            '%' . $query_cp932 . '%',
            '%' . $yomi_half_cp932 . '%',
            '%' . $yomi_full_cp932 . '%'
        ]);

        $rows = $stmt->fetchAll();
        $patients = [];
        foreach ($rows as $r) {
            $patients[] = [
                'id'          => $r['id'],
                'kanja_kanji' => mb_convert_encoding($r['kanja_kanji'] ?? '', 'UTF-8', 'CP932'),
                'kanja_yomi'  => trim(mb_convert_encoding($r['kanja_yomi'] ?? '', 'UTF-8', 'CP932')),
                'latest_date' => $r['latest_date'] ?? '',
                'exam_count'  => (int)$r['exam_count']
            ];
        }

        ob_clean();
        echo json_encode(['status' => 'success', 'data' => $patients]);
    } catch (PDOException $e) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'データベース検索エラー: ' . $e->getMessage()]);
    }
    exit;
}

// デフォルトを本日の日付（YYYY/MM/DD）に設定
$input_date = $_GET['date'] ?? date('Y/m/d');
$target_date = str_replace('-', '/', $input_date);

$raw_filter_id = $_GET['patient_id'] ?? '';
// 5桁未満等で先頭が0パディングされている場合の除去（例: 08920 -> 8920）
$filter_id     = preg_replace('/^0+([0-9]+)$/', '$1', trim($raw_filter_id));
// デフォルトモードを 'custom' （カスタムビューワモード）に設定
$view_mode   = $_GET['mode'] ?? 'custom';

function format_wareki_birth($birth_str) {
    if (empty($birth_str)) return '';
    $clean = str_replace(['-', '.'], '/', trim($birth_str));
    $parts = explode('/', $clean);
    if (count($parts) < 3) return htmlspecialchars($birth_str, ENT_QUOTES, 'UTF-8');
    $y = (int)$parts[0];
    $m = (int)$parts[1];
    $d = (int)$parts[2];
    $ymd = sprintf('%04d%02d%02d', $y, $m, $d);
    
    $era = '';
    $era_year = 0;
    if ($ymd >= '20190501') {
        $era = '令'; $era_year = $y - 2018;
    } elseif ($ymd >= '19890108') {
        $era = '平'; $era_year = $y - 1988;
    } elseif ($ymd >= '19261225') {
        $era = '昭'; $era_year = $y - 1925;
    } elseif ($ymd >= '19120730') {
        $era = '大'; $era_year = $y - 1911;
    } elseif ($ymd >= '18680125') {
        $era = '明'; $era_year = $y - 1867;
    }
    $era_str = $era ? ($era_year === 1 ? "{$era}元" : "{$era}{$era_year}") : '';
    $formatted = sprintf('%04d/%02d/%02d', $y, $m, $d);
    return $era_str ? "{$formatted} ({$era_str})" : $formatted;
}

$results = [];
$distinct_kensamei = [];
$error_message = '';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    $pdo->exec("SET client_encoding TO 'SHIFT_JIS'");

    $sql = "
        SELECT 
            g.renban, g.id, g.kanja_kanji, g.kanja_yomi, g.kensamei, g.date, g.sindan1, 
            g.folder, g.sex, g.age, g.doctor, g.daytime,
            n.birth
        FROM gazou_tbl g
        LEFT JOIN name_tbl n ON g.id = CAST(n.id AS character varying)
        WHERE 1=1
    ";
    $params = [];

    if (!empty($filter_id)) {
        $sql .= " AND (g.id = ? OR g.id = ?)";
        $params[] = $filter_id;
        $params[] = trim($raw_filter_id);
    } else {
        $sql .= " AND g.date LIKE ?";
        $params[] = $target_date . '%';
    }

    $sql .= " ORDER BY g.id DESC, g.renban DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_results = $stmt->fetchAll();

    // 抽出された患者IDから過去検査の累計件数を一括取得
    $counts = [];
    $ids = array_unique(array_filter(array_column($raw_results, 'id')));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $countStmt = $pdo->prepare("SELECT id, COUNT(*) as c FROM gazou_tbl WHERE id IN ($placeholders) GROUP BY id");
        $countStmt->execute(array_values($ids));
        $counts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    foreach ($raw_results as $row) {
        $raw_kensamei = mb_convert_encoding($row['kensamei'] ?? '', 'UTF-8', 'CP932');
        
        $display_kensamei = $raw_kensamei;
        $is_ecg = false;

        if ($raw_kensamei === 'ES_General') {
            $display_kensamei = '内視鏡';
        } elseif ($raw_kensamei === 'US_Adult_Abd_') {
            $display_kensamei = 'US';
        } elseif (strpos($raw_kensamei, '心電図') !== false || stripos($raw_kensamei, 'ecg') !== false) {
            $is_ecg = true;
        }

        if (!in_array($display_kensamei, $distinct_kensamei)) {
            $distinct_kensamei[] = $display_kensamei;
        }

        $raw_folder = $row['folder'] ?? '';
        $unc_folder = str_replace('/', '\\', $raw_folder);

        if ($is_ecg || stripos($unc_folder, '.ecg') !== false) {
            $is_ecg = true;
        }

        // 検査時刻の抽出 (YYYYMMDDHHMMSS... -> HH:MM)
        $raw_daytime = $row['daytime'] ?? '';
        $time_display = '';
        if (strlen($raw_daytime) >= 12) {
            $h = substr($raw_daytime, 8, 2);
            $m = substr($raw_daytime, 10, 2);
            $time_display = "{$h}:{$m}";
        }

        $birth_display = format_wareki_birth($row['birth'] ?? '');
        $doctor_display = trim(mb_convert_encoding($row['doctor'] ?? '', 'UTF-8', 'CP932'));
        $total_exams = $counts[$row['id']] ?? 1;

        $results[] = [
            'renban'      => $row['renban'],
            'id'          => $row['id'],
            'kanja_kanji' => mb_convert_encoding($row['kanja_kanji'] ?? '', 'UTF-8', 'CP932'),
            'kanja_yomi'  => trim(mb_convert_encoding($row['kanja_yomi'] ?? '', 'UTF-8', 'CP932')),
            'kensamei'    => $display_kensamei,
            'raw_kensamei'=> $raw_kensamei,
            'is_ecg'      => $is_ecg,
            'date'        => $row['date'],
            'time'        => $time_display,
            'birth'       => $birth_display,
            'doctor'      => $doctor_display,
            'total_exams' => $total_exams,
            'sindan1'     => mb_convert_encoding($row['sindan1'] ?? '', 'UTF-8', 'CP932'),
            'sex'         => mb_convert_encoding($row['sex'] ?? '', 'UTF-8', 'CP932'),
            'age'         => $row['age'],
            'folder'      => $unc_folder,
            'raw_folder'  => $raw_folder
        ];
    }
    sort($distinct_kensamei);

} catch (PDOException $e) {
    $error_message = "データベース接続エラー: " . $e->getMessage();
}

$prev_date = '';
$next_date = '';
if (!empty($target_date) && preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $target_date)) {
    $dt = new DateTime($target_date);
    $prev_date = $dt->modify('-1 day')->format('Y/m/d');
    $dt = new DateTime($target_date);
    $next_date = $dt->modify('+1 day')->format('Y/m/d');
}

$calendar_date = str_replace('/', '-', $target_date);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RS-Base 画像ファイリング一覧</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1d4ed8;       /* ロイヤルブルー */
            --primary-hover: #1e40af;       /* ディープロイヤルブルー */
            --accent-blue: #2563eb;
            --bg-color: #e2e8f0;            /* 背景を洗練されたスレートグレーに */
            --card-bg: #ffffff;
            --text-main: #0f172a;           /* 視認性の高いスレート900 */
            --text-muted: #64748b;          /* スレート500 */
            --border-color: #cbd5e1;        /* スレート300（すっきりとした輪郭線） */
            --cell-padding-y: 3px;          /* 縦方向の圧縮: 5px -> 3px */
            --cell-padding-x: 8px;          /* 横パディング: 10px -> 8px */
            --font-size: 0.82rem;           /* スマートコンパクト文字サイズ */
        }

        html, body {
            height: 100vh;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            box-sizing: border-box;
        }
        .container {
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 8px 12px;
            box-sizing: border-box;
        }

        /* トップメニュー：濃い青→青、左から右へのグラデーションバック＆縦圧縮 */
        header {
            background: linear-gradient(90deg, #0b192c 0%, #1e3a8a 52%, #2563eb 100%);
            padding: 7px 14px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(11, 25, 44, 0.22);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 6px;             /* 縦圧縮 */
            color: #ffffff;
            flex-shrink: 0;
        }
        
        /* タイトルエリア（横並びで1行にまとめて縦幅を圧縮） */
        .title-area {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        h1 {
            font-size: 1.05rem;
            margin: 0;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            letter-spacing: -0.01em;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }
        .sub-help-link {
            font-size: 0.72rem;
            color: #bae6fd;
            background: rgba(255, 255, 255, 0.12);
            padding: 2px 8px;
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            font-weight: 500;
        }
        .sub-help-link:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.22);
            border-color: rgba(255, 255, 255, 0.4);
            text-decoration: none;
        }

        .controls { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        
        /* トップメニュー内コントロール（ダーク背景に合わせたガラス調＆コンパクト） */
        .mode-selector-box {
            display: flex;
            align-items: center;
            gap: 5px;
            background: rgba(255, 255, 255, 0.14);
            padding: 2px 8px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.24);
        }
        .mode-label {
            font-size: 0.73rem;
            font-weight: 600;
            color: #e2e8f0;
        }
        .mode-select {
            border: none;
            background: transparent;
            font-weight: 600;
            color: #ffffff;
            cursor: pointer;
            outline: none;
            font-size: 0.78rem;
        }
        .mode-select option {
            background: #0f172a;
            color: #ffffff;
        }

        .btn-nav {
            background-color: rgba(255, 255, 255, 0.16);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.28);
            padding: 3px 8px;
            border-radius: 5px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.78rem;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
        }
        .btn-nav:hover {
            background-color: rgba(255, 255, 255, 0.28);
            color: #ffffff;
        }

        .date-input {
            padding: 2px 6px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 5px;
            font-size: 0.78rem;
            outline: none;
            background: #ffffff;
            color: #0f172a;
            font-weight: 600;
            height: 24px;
            box-sizing: border-box;
        }

        .btn-download {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: #ffffff;
            border: 1px solid #34d399;
            font-weight: 600;
            font-size: 0.78rem;
            padding: 3px 9px;
            border-radius: 5px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
            transition: all 0.15s;
        }
        .btn-download:hover {
            background: linear-gradient(135deg, #047857 0%, #059669 100%);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        }
        
        .btn-help {
            background-color: rgba(255, 255, 255, 0.18);
            color: #fef08a;
            border: 1px solid rgba(254, 240, 138, 0.4);
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 5px;
            font-size: 0.8rem;
            transition: all 0.15s;
        }
        .btn-help:hover {
            background-color: #eab308;
            color: #ffffff;
            border-color: #facc15;
        }

        .reset-filter {
            background-color: #ef4444;
            color: #ffffff;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.78rem;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid #dc2626;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            transition: background 0.15s;
        }
        .reset-filter:hover { background-color: #dc2626; }

        /* フィルタパネル（縦圧縮＆クールグレー枠＆固定） */
        .filter-panel {
            background: var(--card-bg);
            padding: 6px 12px;              /* 縦圧縮 */
            border-radius: 8px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 6px;             /* 縦圧縮 */
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex-shrink: 0;
        }
        .filter-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .filter-label {
            font-size: 0.74rem;
            font-weight: 700;
            color: #475569;
            min-width: 82px;
        }
        .toggle-btn {
            background-color: #f1f5f9;
            color: #334155;
            border: 1px solid var(--border-color);
            padding: 2px 7px;
            border-radius: 12px;
            font-size: 0.74rem;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
            line-height: 1.3;
        }
        .toggle-btn:hover {
            background-color: #e2e8f0;
            color: #0f172a;
        }
        .toggle-btn.active {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: #ffffff;
            border-color: #1e40af;
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(30, 64, 175, 0.35);
        }

        /* データカード & テーブル（固定ヘッダー＆テーブル領域独立スクロール） */
        .card {
            background: var(--card-bg);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow-y: auto;
            overflow-x: auto;
            flex: 1;
            min-height: 0; /* Flexbox内で縦スクロール領域を確立 */
        }
        .card::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .card::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        .card::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .card::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        table { width: 100%; border-collapse: collapse; text-align: left; font-size: var(--font-size); }
        th, td {
            padding: var(--cell-padding-y) var(--cell-padding-x);
            border-bottom: 1px solid #e2e8f0;
            line-height: 1.35;
        }
        th {
            position: sticky;
            top: 0;
            z-index: 20;
            background-color: #f1f5f9;
            font-weight: 700;
            color: #475569;
            font-size: 0.74rem;
            text-transform: uppercase;
            cursor: pointer;
            border-bottom: 2px solid var(--border-color);
            box-shadow: inset 0 -2px 0 var(--border-color);
            user-select: none;
        }
        th:hover { color: var(--primary-color); background-color: #e2e8f0; }

        /* 🎨 患者ごとの背景交互グループ色 (視線の横移動で見失わないゼブラ・グルーピング) */
        tr.row-pat-even {
            background-color: #ffffff;
        }
        tr.row-pat-odd {
            background-color: #f1f7fe; /* 爽やかで目に優しい淡いスカイブルー */
        }
        /* 患者グループの変わり目に明確な上部境界線 */
        tr.pat-group-start td {
            border-top: 2px solid #93c5fd !important;
        }
        /* ホバー時は患者グループに関わらずくっきりハイライト */
        tr.row-pat-even:hover,
        tr.row-pat-odd:hover {
            background-color: #dbeafe !important;
            transition: background-color 0.12s ease;
        }

        /* 🕒 日時・🎂 生年月日・よみがな サブテキスト */
        .cell-sub-time {
            font-size: 0.72rem;
            color: #475569;
            font-family: monospace;
            font-weight: 600;
            margin-top: 1px;
        }
        .cell-sub-birth {
            font-size: 0.71rem;
            color: #475569;
            margin-top: 2px;
            white-space: nowrap;
        }
        .cell-sub-yomi {
            color: var(--text-muted);
            display: block;
            font-size: 0.7rem;
            margin-top: 1px;
        }

        /* 📋 過去検査累計件数バッジ */
        .badge-total-exams {
            background: #e0e7ff;
            color: #3730a3;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 0.68rem;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid #c7d2fe;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .badge-total-exams:hover {
            background: #c7d2fe;
            color: #1e1b4b;
        }

        /* 🏥 施行医・依頼元バッジ */
        .doctor-badge {
            background: #f8fafc;
            color: #334155;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.74rem;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            display: inline-block;
            max-width: 140px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* 📝 診断・所見タグ */
        .sindan-tag {
            background: #f1f5f9;
            color: #0f172a;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-family: monospace;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            display: inline-block;
        }
        
        .badge {
            display: inline-block; padding: 1px 5px; border-radius: 4px; font-size: 0.75rem; font-weight: 600;
            background-color: #e0f2fe; color: #0369a1; text-decoration: none; transition: background 0.2s;
        }
        .badge:hover { background-color: #bae6fd; }
        .badge-kensamei { background-color: #eff6ff; color: #1d4ed8; }
        .badge-ecg { background-color: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8; }
        .badge-ecg:hover { background-color: #fce7f3; }

        .badge-custom {
            background-color: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; cursor: pointer;
            display: inline-block; padding: 2px 6px; border-radius: 4px; font-weight: 600; font-size: 0.78rem; user-select: none;
            transition: all 0.15s;
        }
        .badge-custom:hover { background-color: #dcfce7; border-color: #86efac; }

        .badge-jpeg {
            background-color: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; cursor: pointer;
            display: inline-block; padding: 2px 6px; border-radius: 4px; font-weight: 600; font-size: 0.78rem; user-select: none;
            transition: all 0.15s;
        }
        .badge-jpeg:hover { background-color: #ffedd5; border-color: #fdba74; }

        .patient-link { color: var(--primary-color); font-weight: 700; text-decoration: none; }
        .patient-link:hover { text-decoration: underline; color: var(--primary-hover); }
        
        .karte-link {
            font-family: monospace;
            background-color: #f8fafc;
            padding: 1px 5px;
            border-radius: 4px;
            color: #1e3a8a;
            text-decoration: none;
            border: 1px solid var(--border-color);
            display: inline-block;
            transition: all 0.15s;
            font-weight: 600;
        }
        .karte-link:hover {
            background-color: #eff6ff;
            color: var(--primary-color);
            border-color: #93c5fd;
        }

        .folder-click {
            color: #0f766e;
            cursor: pointer;
            font-weight: 600;
            text-decoration: underline;
            text-decoration-style: dotted;
        }
        .folder-click:hover {
            color: #0d9488;
            background-color: #ccfbf1;
        }

        .error-box { background-color: #fee2e2; color: #b91c1c; padding: 10px 14px; border-radius: 6px; margin-bottom: 12px; font-size: 0.85rem; }

        #toast {
            visibility: hidden;
            min-width: 260px;
            background-color: #0f172a;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 10px 14px;
            position: fixed;
            z-index: 3000;
            left: 50%;
            bottom: 24px;
            transform: translateX(-50%);
            font-size: 0.84rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            border: 1px solid #334155;
        }
        #toast.show { visibility: visible; animation: fadein 0.4s, fadeout 0.4s 2.5s; }
        @keyframes fadein { from { bottom: 0; opacity: 0; } to { bottom: 24px; opacity: 1; } }
        @keyframes fadeout { from { bottom: 24px; opacity: 1; } to { bottom: 0; opacity: 0; } }

        /* 検索ボタン（虫眼鏡）：ノーブル・ブルー配色＆スマートコンパクト（32px） */
        .search-icon-btn {
            background: linear-gradient(135deg, #0b192c 0%, #1e40af 60%, #2563eb 100%);
            color: #ffffff;
            border: 1.5px solid #60a5fa;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(30, 64, 175, 0.28);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            outline: none;
            flex-shrink: 0;
            animation: pulse-ring 3s infinite cubic-bezier(0.4, 0, 0.6, 1);
        }
        .search-icon-btn:hover {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
            color: #ffffff;
            border-color: #93c5fd;
            transform: translateY(-1px) scale(1.05);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.45);
        }
        .search-icon-btn:active {
            transform: translateY(0) scale(0.96);
        }
        .search-icon-btn .search-svg {
            transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .search-icon-btn:hover .search-svg {
            transform: rotate(12deg) scale(1.1);
        }
        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.45); }
            70% { box-shadow: 0 0 0 5px rgba(37, 99, 235, 0); }
            100% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); }
        }

        /* 検索モーダル：ヘッダーをトップメニューと同調のグラデーション、全体をノーブル・ブルーでセンス良く */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(11, 25, 44, 0.65);
            backdrop-filter: blur(4px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            animation: modalFadeIn 0.2s ease-out;
        }
        .modal-card {
            background: #ffffff;
            width: 100%;
            max-width: 640px;
            max-height: 85vh;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid #cbd5e1;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.96) translateY(-8px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header {
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(90deg, #0b192c 0%, #1e3a8a 60%, #2563eb 100%);
            color: #ffffff;
        }
        .modal-title {
            font-size: 1rem;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-close-btn {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            line-height: 1;
            color: #cbd5e1;
            cursor: pointer;
            border-radius: 4px;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
        }
        .modal-close-btn:hover {
            background: rgba(239, 68, 68, 0.85);
            color: #ffffff;
        }
        .modal-body {
            padding: 14px 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            overflow: hidden;
            flex: 1;
        }
        .search-input-group {
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .search-input {
            flex: 1;
            padding: 8px 34px 8px 12px;
            font-size: 0.9rem;
            border: 2px solid #cbd5e1;
            border-radius: 6px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .search-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }
        .clear-input-btn {
            position: absolute;
            right: 84px;
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 1.1rem;
            cursor: pointer;
            padding: 4px 6px;
            line-height: 1;
        }
        .clear-input-btn:hover { color: #475569; }
        .search-submit-btn {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.86rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            box-shadow: 0 1px 3px rgba(30, 64, 175, 0.3);
        }
        .search-submit-btn:hover {
            background: linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 100%);
            box-shadow: 0 2px 6px rgba(30, 64, 175, 0.4);
        }
        .modal-hint {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.74rem;
            color: #64748b;
        }
        .search-results-list {
            flex: 1;
            overflow-y: auto;
            max-height: 400px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: #fafafa;
        }
        .search-empty-state {
            padding: 28px 16px;
            text-align: center;
            color: #94a3b8;
            font-size: 0.84rem;
            line-height: 1.5;
        }
        .search-loading-spinner {
            padding: 28px 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            color: var(--primary-color);
            font-size: 0.84rem;
        }
        .spinner {
            width: 24px;
            height: 24px;
            border: 3px solid #dbeafe;
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .patient-candidate-item {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.15s;
            background: #ffffff;
            border-left: 3px solid transparent;
        }
        .patient-candidate-item:last-child {
            border-bottom: none;
        }
        .patient-candidate-item:hover, .patient-candidate-item.selected {
            background: #eff6ff;
            border-left-color: #2563eb;
        }
        .patient-candidate-main {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .patient-id-badge {
            font-family: monospace;
            font-weight: 700;
            font-size: 0.82rem;
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #bfdbfe;
            white-space: nowrap;
        }
        .patient-name-box {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }
        .patient-kanji {
            font-weight: 600;
            font-size: 0.88rem;
            color: #0f172a;
        }
        .patient-yomi {
            font-size: 0.72rem;
            color: #64748b;
        }
        .patient-candidate-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
            font-size: 0.76rem;
        }
        .patient-date {
            color: #475569;
        }
        .patient-count-badge {
            color: #0f766e;
            padding: 1px 6px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.72rem;
            border: 1px solid #a7f3d0;
            background: #ecfdf5;
        }
        .patient-select-arrow {
            color: #94a3b8;
            font-weight: 700;
            font-size: 0.9rem;
            transition: transform 0.15s;
        }
        .patient-candidate-item:hover .patient-select-arrow,
        .patient-candidate-item.selected .patient-select-arrow {
            color: var(--primary-color);
            transform: translateX(2px);
        }

        /* 👁️ ブラウザ内蔵DICOM閲覧ボタン */
        .btn-inline-view {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            border: 1px solid #38bdf8;
            padding: 2px 7px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.74rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s;
            box-shadow: 0 1px 3px rgba(2, 132, 199, 0.3);
            user-select: none;
            text-decoration: none;
            vertical-align: middle;
        }
        .btn-inline-view:hover {
            background: linear-gradient(135deg, #0369a1 0%, #075985 100%);
            box-shadow: 0 2px 6px rgba(2, 132, 199, 0.5);
            transform: translateY(-1px);
        }

        /* 📈 ブラウザ内蔵心電図(ECG)専用閲覧ボタン */
        .btn-inline-ecg {
            background: linear-gradient(135deg, #059669 0%, #0d9488 100%) !important;
            border-color: #34d399 !important;
            box-shadow: 0 1px 3px rgba(5, 150, 105, 0.3) !important;
        }
        .btn-inline-ecg:hover {
            background: linear-gradient(135deg, #047857 0%, #0f766e 100%) !important;
            box-shadow: 0 2px 6px rgba(5, 150, 105, 0.5) !important;
        }

        /* 🏥 DICOMビューワモーダル (dicom_view.php iframe読込) */
        .dcm-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(3, 7, 18, 0.92);
            backdrop-filter: blur(8px);
            z-index: 5000;
            display: none;
            flex-direction: column;
            overflow: hidden;
            animation: dcmModalFadeIn 0.2s ease-out;
        }
        @keyframes dcmModalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .dcm-modal-iframe {
            width: 100vw;
            height: 100vh;
            border: none;
            background: #07090e;
            display: block;
        }

        /* 👁️ 閲覧ボタン 長押し・右クリック コンテキストメニュー */
        .dcm-context-menu {
            position: fixed;
            z-index: 6000;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.7);
            padding: 6px;
            min-width: 230px;
            display: none;
            flex-direction: column;
            gap: 2px;
            animation: ctxFadeIn 0.15s ease-out;
            user-select: none;
        }
        @keyframes ctxFadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .ctx-header {
            padding: 6px 10px 6px;
            font-size: 0.76rem;
            color: #94a3b8;
            border-bottom: 1px solid #1e293b;
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .ctx-item {
            padding: 8px 12px;
            font-size: 0.85rem;
            color: #f1f5f9;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: background 0.15s, color 0.15s;
        }
        .ctx-item:hover {
            background: #0284c7;
            color: #ffffff;
        }
        .ctx-item .badge-hint {
            font-size: 0.68rem;
            background: rgba(255, 255, 255, 0.15);
            padding: 2px 6px;
            border-radius: 4px;
            color: #e2e8f0;
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- ヘッダー（濃紺→青グラデーション＆縦圧縮レイアウト） -->
        <header>
            <div class="title-area">
                <h1>🏥 RS-Base 検査・画像ビューア <span style="font-size:0.68rem; vertical-align:middle; background:rgba(255,255,255,0.18); border:1px solid rgba(255,255,255,0.3); border-radius:10px; padding:2px 8px; font-weight:600; color:#bae6fd; letter-spacing:0.04em;">v2.0</span></h1>
                <a href="help.html#sec3" target="_blank" class="sub-help-link" title="胃がん検診データ作成補助機能 導入マニュアルを開きます">
                    胃がん検診データ作成 ↗
                </a>
                <a href="canvas_view.php<?= !empty($filter_id) ? '?patient_id=' . urlencode($filter_id) : '' ?>" class="sub-help-link" style="background:rgba(59, 130, 246, 0.25); border-color:#60a5fa; color:#bae6fd;" title="JSON Canvas準拠の患者検査タイムライン・無限キャンバスを開く">
                    🗺️ 検査キャンバス ↗
                </a>
            </div>

            <div class="controls">
                <div class="mode-selector-box">
                    <span class="mode-label">モード:</span>
                    <select onchange="changeViewMode(this.value)" class="mode-select">
                        <option value="rsbase" <?= $view_mode === 'rsbase' ? 'selected' : '' ?>>RSBaseモード (標準)</option>
                        <option value="custom" <?= $view_mode === 'custom' ? 'selected' : '' ?>>カスタムビューワモード (直起動)</option>
                        <option value="jpeg"   <?= $view_mode === 'jpeg' ? 'selected' : '' ?>>胃がん検診JPEG変換モード</option>
                    </select>
                </div>

                <?php if (!empty($filter_id)): ?>
                    <a href="index.php?date=<?= htmlspecialchars($target_date, ENT_QUOTES, 'UTF-8') ?>&mode=<?= $view_mode ?>" class="reset-filter">👤 患者選択解除</a>
                <?php endif; ?>

                <?php if (empty($filter_id)): ?>
                    <div style="display:flex; align-items:center; gap:4px;">
                        <a href="index.php?date=<?= $prev_date ?>&mode=<?= $view_mode ?>" class="btn-nav">◀ 前日</a>
                        <form method="GET" action="index.php" style="display:flex; gap:4px; align-items:center; margin:0;">
                            <input type="hidden" name="mode" value="<?= htmlspecialchars($view_mode, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="date" name="date" class="date-input" value="<?= htmlspecialchars($calendar_date, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()">
                        </form>
                        <a href="index.php?date=<?= $next_date ?>&mode=<?= $view_mode ?>" class="btn-nav">翌日 ▶</a>
                    </div>
                <?php endif; ?>

                <?php if ($view_mode === 'custom' || $view_mode === 'jpeg'): ?>
                    <a href="watcher.vbs" class="btn-download" download title="常駐監視スクリプト（watcher.vbs）をダウンロードします">
                        📥 常駐スクリプトDL
                    </a>
                    <a href="help.html" target="_blank" class="btn-help" title="ヘルプを開きます">
                        ?
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- フィルタパネル -->
        <div class="filter-panel">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                <div style="flex: 1; display: flex; flex-direction: column; gap: 10px; min-width: 0;">
                    <div class="filter-row" id="kensaToggleContainer">
                        <span class="filter-label">検査絞り込み:</span>
                        <button type="button" class="toggle-btn active" data-kensa="">すべて表示</button>
                        <?php foreach ($distinct_kensamei as $kname): ?>
                            <button type="button" class="toggle-btn" data-kensa="<?= htmlspecialchars($kname, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($kname, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="filter-row" id="initialToggleContainer">
                        <span class="filter-label">よみがな抽出:</span>
                        <button type="button" class="toggle-btn active" data-initial="">すべて</button>
                        <button type="button" class="toggle-btn" data-initial="ｱｲｳｴｵ">ア行</button>
                        <button type="button" class="toggle-btn" data-initial="ｶｷｸｹｺｶﾞｷﾞｸﾞｹﾞｺﾞ">カ行</button>
                        <button type="button" class="toggle-btn" data-initial="ｻｼｽｾｿｻﾞｼﾞｽﾞｾﾞｿﾞ">サ行</button>
                        <button type="button" class="toggle-btn" data-initial="ﾀﾁﾂﾃﾄﾀﾞﾁﾞﾂﾞﾃﾞﾄﾞｯ">タ行</button>
                        <button type="button" class="toggle-btn" data-initial="ﾅﾆﾇﾈﾉ">ナ行</button>
                        <button type="button" class="toggle-btn" data-initial="ﾊﾋﾌﾍﾎﾊﾞﾋﾞﾌﾞﾍﾞﾎﾞﾊﾟﾋﾟﾌﾟﾍﾟﾎﾟ">ハ行</button>
                        <button type="button" class="toggle-btn" data-initial="ﾏﾐﾑﾒﾓ">マ行</button>
                        <button type="button" class="toggle-btn" data-initial="ﾔﾕﾖ">ヤ行</button>
                        <button type="button" class="toggle-btn" data-initial="ﾗﾘﾙﾚﾛ">ラ行</button>
                        <button type="button" class="toggle-btn" data-initial="ﾜｦﾝ">ワ行</button>
                    </div>
                </div>

                <!-- 絞り込み右端：虫眼鏡アイコンボタン（アニメーション付き） -->
                <button type="button" class="search-icon-btn" id="openSearchBtn" title="患者検索 (ID・氏名) [ショートカット: / または F2]" aria-label="患者検索">
                    <svg class="search-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="7"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </button>
            </div>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="error-box"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
            <div class="card">
                <table id="dataTable">
                    <thead>
                        <tr>
                            <th style="width: 75px;">連番 (編集)</th>
                            <th style="width: 65px; text-align: center;">閲覧</th>
                            <th onclick="sortTable('kensa')">検査名 <span id="sort-kensa">↕</span></th>
                            <th style="width: 100px;">検査日時</th>
                            <th style="width: 95px;" onclick="sortTable('id')">検査ID <span id="sort-id">▼</span></th>
                            <th onclick="sortTable('yomi')">患者氏名 (よみ順) <span id="sort-yomi">↕</span></th>
                            <th style="width: 135px;">年齢 / 生年月日</th>
                            <th style="width: 120px;">施行医・依頼元</th>
                            <th>診断・所見</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($results) > 0): ?>
                            <?php 
                                $prev_id = null;
                                $pat_group_idx = 0;
                            ?>
                            <?php foreach ($results as $row): ?>
                            <?php 
                                if ($row['id'] !== $prev_id) {
                                    $prev_id = $row['id'];
                                    $pat_group_idx++;
                                    $is_group_start = true;
                                } else {
                                    $is_group_start = false;
                                }
                                $group_cls = ($pat_group_idx % 2 === 0) ? 'row-pat-even' : 'row-pat-odd';
                                if ($is_group_start) {
                                    $group_cls .= ' pat-group-start';
                                }

                                $encoded_kensamei = urlencode(mb_convert_encoding($row['raw_kensamei'], 'CP932', 'UTF-8'));
                                $encoded_name     = urlencode(mb_convert_encoding($row['kanja_kanji'], 'CP932', 'UTF-8'));
                                $unc_folder_display = str_replace('/', '\\', $row['folder']);
                                
                                // バッジ側の起動モード判定（従来通り）
                                if ($view_mode === 'custom') {
                                    $badge_launch_mode = $row['is_ecg'] ? 'ECG' : 'NORMAL';
                                    $badge_html = '<span class="badge-custom" data-folder="' . htmlspecialchars($unc_folder_display, ENT_QUOTES, 'UTF-8') . '" data-mode="' . $badge_launch_mode . '" onclick="directLaunchFromElement(this)">' . htmlspecialchars($row['kensamei'], ENT_QUOTES, 'UTF-8') . ' 🚀</span>';
                                } elseif ($view_mode === 'jpeg') {
                                    $badge_html = '<span class="badge-jpeg" data-folder="' . htmlspecialchars($unc_folder_display, ENT_QUOTES, 'UTF-8') . '" data-mode="JPEG" onclick="directLaunchFromElement(this)">' . htmlspecialchars($row['kensamei'], ENT_QUOTES, 'UTF-8') . ' 📷 JPEG変換</span>';
                                } else {
                                    if ($row['is_ecg']) {
                                        $viewer_url = "http://192.168.1.164/~rsn/ecg_st.cgi?fukuda_d=" 
                                        . urlencode($row['id']) . "=" 
                                        . urlencode($row['date']) . "=" 
                                        . $row['raw_folder'] . "==" 
                                        . urlencode($row['renban']) . "=5";
                                        $badge_class = "badge-ecg";
                                    } else {
                                        $viewer_url = "http://192.168.1.164/~rsn/2000.cgi?fiber=" 
                                                    . urlencode($row['id']) . "=" 
                                                    . urlencode($row['date']) . "=" 
                                                    . urlencode($row['folder']) . "=" 
                                                    . $encoded_name . "==" 
                                                    . $encoded_kensamei . "=10";
                                        $badge_class = "badge-kensamei";
                                    }
                                    $badge_html = '<a href="' . htmlspecialchars($viewer_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" class="badge ' . $badge_class . '" style="text-decoration:none; padding: 4px 8px;">' . htmlspecialchars($row['kensamei'], ENT_QUOTES, 'UTF-8') . ' 🔗</a>';
                                }

                                $edit_url = "http://192.168.1.164/~rsn/2000.cgi?" 
                                          . urlencode($row['renban']) . "|" 
                                          . urlencode($row['date']) . "|" 
                                          . urlencode($row['folder']);

                                $karte_url = "http://192.168.1.16/karteview/karteview.php?patient_no=" . urlencode($row['id']) . "&source=new&do_search=1";
                            ?>
                            <tr class="<?= $group_cls ?>"
                                data-kensamei="<?= htmlspecialchars($row['kensamei'], ENT_QUOTES, 'UTF-8') ?>" 
                                data-yomi="<?= htmlspecialchars($row['kanja_yomi'], ENT_QUOTES, 'UTF-8') ?>"
                                data-id="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <td>
                                    <a href="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="badge" title="本家の編集・カスタマイズ画面を開く">
                                        <?= htmlspecialchars($row['renban'], ENT_QUOTES, 'UTF-8') ?> ✏️
                                    </a>
                                </td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <?php if ($row['is_ecg']): ?>
                                        <button type="button" class="btn-inline-view btn-inline-ecg"
                                                data-file="<?= htmlspecialchars($unc_folder_display, ENT_QUOTES, 'UTF-8') ?>"
                                                data-folder="<?= htmlspecialchars($unc_folder_display, ENT_QUOTES, 'UTF-8') ?>"
                                                data-name="<?= htmlspecialchars($row['kanja_kanji'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-id="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-kensa="<?= htmlspecialchars($row['kensamei'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-date="<?= htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-sindan="<?= htmlspecialchars($row['sindan1'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-is-ecg="1"
                                                onclick="openViewerFromButton(this)"
                                                title="心電図ビューワーを起動 (クリック: モーダル閲覧 / 長押し・右クリック: 別タブ・別窓選択)">
                                            📈 閲覧
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn-inline-view"
                                                data-folder="<?= htmlspecialchars($unc_folder_display, ENT_QUOTES, 'UTF-8') ?>"
                                                data-name="<?= htmlspecialchars($row['kanja_kanji'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-id="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-kensa="<?= htmlspecialchars($row['kensamei'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-date="<?= htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-sindan="<?= htmlspecialchars($row['sindan1'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-is-ecg="0"
                                                onclick="openViewerFromButton(this)"
                                                title="DICOMビューワーを起動 (クリック: 即座にモーダル閲覧 / 長押し・右クリック: 別タブ・別窓選択)">
                                            👁️ 閲覧
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $badge_html ?>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($row['time'])): ?>
                                        <div class="cell-sub-time">🕒 <?= htmlspecialchars($row['time'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= htmlspecialchars($karte_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="karte-link" title="カルテビューワを開く">
                                        <?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?> 📋
                                    </a>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; justify-content:space-between; gap:4px;">
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <a href="index.php?patient_id=<?= urlencode($row['id']) ?>&mode=<?=$view_mode ?>" class="patient-link" title="この患者の全検査を絞り込み">
                                                <?= htmlspecialchars($row['kanja_kanji'], ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                            <a href="index.php?patient_id=<?= urlencode($row['id']) ?>&mode=<?=$view_mode ?>" class="badge-total-exams" title="この患者の全<?= $row['total_exams'] ?>件の検査履歴を表示">
                                                計<?= $row['total_exams'] ?>件 📋
                                            </a>
                                        </div>
                                        <a href="canvas_view.php?patient_id=<?= urlencode($row['id']) ?>" class="badge" style="background:#1e3a8a; color:#93c5fd; padding:1px 5px; font-size:0.68rem; text-decoration:none;" title="この患者の検査タイムラインを無限キャンバスで開く">
                                            🗺️
                                        </a>
                                    </div>
                                    <div class="cell-sub-yomi"><?= htmlspecialchars($row['kanja_yomi'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <div>
                                        <!-- 年齢部分：クリック時は検査種類に関係なく常に "JPEG" モードでパス複写＆トリガー起動 -->
                                        <span class="folder-click" onclick='copyAndLaunch(this, <?= json_encode($unc_folder_display, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, "JPEG")' title="クリックしてDICOMパスをコピー＆JPEG変換起動">
                                            <?= htmlspecialchars($row['age'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                        </span> 
                                        / <?= htmlspecialchars($row['sex'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php if (!empty($row['birth'])): ?>
                                        <div class="cell-sub-birth">🎂 <?= htmlspecialchars($row['birth'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['doctor'])): ?>
                                        <span class="doctor-badge" title="施行医・依頼元: <?= htmlspecialchars($row['doctor'], ENT_QUOTES, 'UTF-8') ?>">
                                            🏥 <?= htmlspecialchars($row['doctor'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted); font-size:0.74rem;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['sindan1'])): ?>
                                        <span class="sindan-tag"><?= htmlspecialchars($row['sindan1'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="noDataRow">
                                <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 40px;">該当するデータはありません。</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div id="toast">フォルダパスをコピーしました。<br>JPEG変換トリガーを送信しました。</div>

    <!-- 患者検索モーダル -->
    <div id="searchModal" class="modal-overlay" style="display: none;">
        <div class="modal-card">
            <div class="modal-header">
                <div class="modal-title">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #60a5fa;">
                        <circle cx="11" cy="11" r="7"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <span>患者検索（ID・氏名）</span>
                </div>
                <button type="button" class="modal-close-btn" id="closeSearchModalBtn" title="閉じる (Esc)">&times;</button>
            </div>

            <div class="modal-body">
                <div class="search-input-group">
                    <input type="text" id="patientSearchInput" class="search-input" placeholder="患者ID、漢字氏名、フリガナ（かな/カナ）を入力..." autocomplete="off">
                    <button type="button" id="clearSearchInputBtn" class="clear-input-btn" title="入力をクリア" style="display: none;">&times;</button>
                    <button type="button" id="doSearchBtn" class="search-submit-btn">
                        <span>検索</span>
                    </button>
                </div>

                <div class="modal-hint">
                    <span>💡 Enterキーで検索実行 / 候補選択。Escキーで閉じます。</span>
                    <span id="searchResultCount" class="result-count"></span>
                </div>

                <!-- 検索結果一覧コンテナ -->
                <div id="searchResultsContainer" class="search-results-list">
                    <div class="search-empty-state">
                        患者IDまたはお名前を入力して「検索」を押してください。
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 🏥 ブラウザ内蔵・高速DICOMビューワモーダル (dicom_view.php 共通呼出) -->
    <div id="dicomViewerModal" class="dcm-modal-overlay">
        <iframe id="dicomViewerIframe" class="dcm-modal-iframe" src="about:blank" allow="fullscreen"></iframe>
    </div>

    <!-- 👁️ 閲覧ボタン 長押し / 右クリック コンテキストメニュー -->
    <div id="dicomContextMenu" class="dcm-context-menu">
        <div class="ctx-header">
            <span id="ctxPatInfo" style="font-weight:700; color:#f8fafc;">患者名</span>
            <span id="ctxExamInfo" style="color:#38bdf8;">検査</span>
        </div>
        <div class="ctx-item" onclick="execDicomContextAction('modal')">
            <span id="ctxModalLabel">🏥 モーダルで開く</span>
            <span class="badge-hint">通常</span>
        </div>
        <div class="ctx-item" onclick="execDicomContextAction('tab')">
            <span>📑 新しいタブで開く</span>
        </div>
        <div class="ctx-item" onclick="execDicomContextAction('window')">
            <span>🪟 別ウィンドウで開く</span>
            <span class="badge-hint">マルチモニタ推奨</span>
        </div>
    </div>


    <script>
    let currentKensa = "";
    let currentInitialChars = "";
    let currentSortColumn = "id";
    let currentSortAsc = false;

    document.addEventListener("DOMContentLoaded", function() {
        const kensaButtons = document.querySelectorAll('#kensaToggleContainer .toggle-btn');
        kensaButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                kensaButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentKensa = this.getAttribute('data-kensa');
                applyFilters();
            });
        });

        const initialButtons = document.querySelectorAll('#initialToggleContainer .toggle-btn');
        initialButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                initialButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentInitialChars = this.getAttribute('data-initial');
                applyFilters();
            });
        });
    });

    // 年齢部分クリック時の「パスコピー ＆ JPEGトリガー生成」同時実行関数
    function copyAndLaunch(element, folderPath, launchMode) {
        // 1. クリップボードへのパスコピー処理
        const textarea = document.createElement("textarea");
        textarea.value = folderPath;
        textarea.style.position = "fixed";
        textarea.style.top = "0";
        textarea.style.left = "0";
        textarea.style.opacity = "0";
        document.body.appendChild(textarea);
        
        textarea.focus();
        textarea.select();

        try {
            document.execCommand('copy');
            const toast = document.getElementById("toast");
            toast.className = "show";
            setTimeout(() => { toast.className = toast.className.replace("show", ""); }, 3000);
        } catch (err) {
            console.error("コピー失敗", err);
        }
        document.body.removeChild(textarea);

        // 2. トリガーファイル生成＆アプリ起動のAJAX通信
        const originalText = element.textContent;
        element.textContent = "変換起動中...";
        
        const formData = new URLSearchParams();
        formData.append('action', 'launch');
        formData.append('folder', folderPath);
        formData.append('launch_mode', launchMode);

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            if (!response.ok) {
                const text = await response.text();
                throw new Error("HTTPエラー (" + response.status + "):\n" + text);
            }
            return response.json();
        })
        .then(data => {
            if (data.status === 'success') {
                element.textContent = "📷 起動OK";
                setTimeout(() => { element.textContent = originalText; }, 2000);
            } else {
                alert("アプリ側エラー: " + data.message);
                element.textContent = originalText;
            }
        })
        .catch(err => {
            alert("通信・PHPエラー詳細:\n" + err.message);
            element.textContent = originalText;
        });
    }

    // 検査名バッジ等からの個別起動用関数
    function directLaunchFromElement(element) {
        const folderPath = element.getAttribute('data-folder');
        const launchMode = element.getAttribute('data-mode') || 'NORMAL';
        const originalText = element.textContent;
        element.textContent = "起動中...";
        
        const formData = new URLSearchParams();
        formData.append('action', 'launch');
        formData.append('folder', folderPath);
        formData.append('launch_mode', launchMode);

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            if (!response.ok) {
                const text = await response.text();
                throw new Error("HTTPエラー (" + response.status + "):\n" + text);
            }
            return response.json();
        })
        .then(data => {
            if (data.status === 'success') {
                element.textContent = "🚀 起動OK";
                setTimeout(() => { element.textContent = originalText; }, 2000);
            } else {
                alert("アプリ側エラー: " + data.message);
                element.textContent = originalText;
            }
        })
        .catch(err => {
            alert("通信・PHPエラー詳細:\n" + err.message);
            element.textContent = originalText;
        });
    }

    function changeViewMode(mode) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('mode', mode);
        window.location.search = urlParams.toString();
    }

    function applyFilters() {
        const rows = document.querySelectorAll("#dataTable tbody tr[data-kensamei]");
        rows.forEach(row => {
            const kname = row.getAttribute("data-kensamei");
            const yomi = row.getAttribute("data-yomi");

            let kensaMatch = (currentKensa === "" || kname === currentKensa);
            let initialMatch = true;

            if (currentInitialChars !== "") {
                const firstChar = yomi.charAt(0);
                initialMatch = currentInitialChars.includes(firstChar);
            }

            if (kensaMatch && initialMatch) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        });
        reapplyPatientRowColors();
    }

    function reapplyPatientRowColors() {
        const tbody = document.querySelector("#dataTable tbody");
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll("tr[data-id]"));
        let prevId = null;
        let groupIdx = 0;
        rows.forEach(row => {
            if (row.style.display === "none") return;
            const pid = row.getAttribute("data-id");
            if (pid !== prevId) {
                prevId = pid;
                groupIdx++;
                row.classList.add("pat-group-start");
            } else {
                row.classList.remove("pat-group-start");
            }
            row.classList.remove("row-pat-even", "row-pat-odd");
            row.classList.add(groupIdx % 2 === 0 ? "row-pat-even" : "row-pat-odd");
        });
    }

    function sortTable(column) {
        if (currentSortColumn === column) {
            currentSortAsc = !currentSortAsc;
        } else {
            currentSortColumn = column;
            currentSortAsc = true;
        }

        document.getElementById('sort-kensa').textContent = "↕";
        document.getElementById('sort-id').textContent = "↕";
        document.getElementById('sort-yomi').textContent = "↕";
        document.getElementById(`sort-${column}`).textContent = currentSortAsc ? "▲" : "▼";

        const tbody = document.querySelector("#dataTable tbody");
        const rowsArray = Array.from(tbody.querySelectorAll("tr[data-kensamei]"));

        rowsArray.sort((rowA, rowB) => {
            let valA, valB;
            if (column === 'id') {
                valA = rowA.getAttribute('data-id');
                valB = rowB.getAttribute('data-id');
                return currentSortAsc ? valA.localeCompare(valB, 'ja', {numeric: true}) : valB.localeCompare(valA, 'ja', {numeric: true});
            } else if (column === 'yomi') {
                valA = rowA.getAttribute('data-yomi');
                valB = rowB.getAttribute('data-yomi');
                return currentSortAsc ? valA.localeCompare(valB, 'ja') : valB.localeCompare(valA, 'ja');
            } else if (column === 'kensa') {
                valA = rowA.getAttribute('data-kensamei');
                valB = rowB.getAttribute('data-kensamei');
                return currentSortAsc ? valA.localeCompare(valB, 'ja') : valB.localeCompare(valA, 'ja');
            }
        });

        rowsArray.forEach(row => tbody.appendChild(row));
        reapplyPatientRowColors();
    }

    // --- 患者検索モーダル制御ロジック ---
    const searchModal = document.getElementById("searchModal");
    const openSearchBtn = document.getElementById("openSearchBtn");
    const closeSearchModalBtn = document.getElementById("closeSearchModalBtn");
    const patientSearchInput = document.getElementById("patientSearchInput");
    const clearSearchInputBtn = document.getElementById("clearSearchInputBtn");
    const doSearchBtn = document.getElementById("doSearchBtn");
    const searchResultsContainer = document.getElementById("searchResultsContainer");
    const searchResultCount = document.getElementById("searchResultCount");
    const currentViewMode = <?= json_encode($view_mode) ?>;

    let searchCandidates = [];
    let selectedCandidateIndex = -1;

    function openSearchModal() {
        if (!searchModal) return;
        searchModal.style.display = "flex";
        setTimeout(() => {
            if (patientSearchInput) {
                patientSearchInput.focus();
                patientSearchInput.select();
            }
        }, 50);
    }

    function closeSearchModal() {
        if (!searchModal) return;
        searchModal.style.display = "none";
    }

    if (openSearchBtn) {
        openSearchBtn.addEventListener("click", openSearchModal);
    }
    if (closeSearchModalBtn) {
        closeSearchModalBtn.addEventListener("click", closeSearchModal);
    }

    // モーダル背景クリックで閉じる
    if (searchModal) {
        searchModal.addEventListener("click", function(e) {
            if (e.target === searchModal) {
                closeSearchModal();
            }
        });
    }

    // キーボードショートカット (Esc, F2, '/', 上下矢印, Enter)
    document.addEventListener("keydown", function(e) {
        const isModalOpen = (searchModal && searchModal.style.display === "flex");

        if (e.key === "Escape" && isModalOpen) {
            e.preventDefault();
            closeSearchModal();
            return;
        }

        // F2キー または '/' キーでモーダル起動（他の入力欄フォーカス時は無効）
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : "";
        if (!isModalOpen && (e.key === "F2" || (e.key === "/" && activeTag !== "input" && activeTag !== "textarea"))) {
            e.preventDefault();
            openSearchModal();
            return;
        }

        // モーダル内のキーボードナビゲーション
        if (isModalOpen) {
            if (e.key === "ArrowDown") {
                e.preventDefault();
                navigateCandidates(1);
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                navigateCandidates(-1);
            } else if (e.key === "Enter") {
                if (document.activeElement === patientSearchInput) {
                    e.preventDefault();
                    if (searchCandidates.length === 0) {
                        executePatientSearch();
                    } else if (selectedCandidateIndex >= 0 && selectedCandidateIndex < searchCandidates.length) {
                        selectPatient(searchCandidates[selectedCandidateIndex].id);
                    } else if (searchCandidates.length === 1) {
                        selectPatient(searchCandidates[0].id);
                    } else {
                        executePatientSearch();
                    }
                }
            }
        }
    });

    if (patientSearchInput) {
        patientSearchInput.addEventListener("input", function() {
            if (clearSearchInputBtn) {
                clearSearchInputBtn.style.display = (this.value.length > 0) ? "block" : "none";
            }
        });
    }

    if (clearSearchInputBtn) {
        clearSearchInputBtn.addEventListener("click", function() {
            if (patientSearchInput) {
                patientSearchInput.value = "";
                patientSearchInput.focus();
            }
            this.style.display = "none";
            searchCandidates = [];
            selectedCandidateIndex = -1;
            if (searchResultCount) searchResultCount.textContent = "";
            if (searchResultsContainer) {
                searchResultsContainer.innerHTML = '<div class="search-empty-state">患者IDまたはお名前を入力して「検索」を押してください。</div>';
            }
        });
    }

    if (doSearchBtn) {
        doSearchBtn.addEventListener("click", function() {
            executePatientSearch();
        });
    }

    function navigateCandidates(step) {
        if (!searchCandidates || searchCandidates.length === 0) return;
        selectedCandidateIndex += step;
        if (selectedCandidateIndex < 0) selectedCandidateIndex = searchCandidates.length - 1;
        if (selectedCandidateIndex >= searchCandidates.length) selectedCandidateIndex = 0;
        updateCandidateHighlight();
    }

    function updateCandidateHighlight() {
        if (!searchResultsContainer) return;
        const items = searchResultsContainer.querySelectorAll(".patient-candidate-item");
        items.forEach((item, idx) => {
            if (idx === selectedCandidateIndex) {
                item.classList.add("selected");
                item.scrollIntoView({ block: "nearest" });
            } else {
                item.classList.remove("selected");
            }
        });
    }

    function executePatientSearch() {
        if (!patientSearchInput || !searchResultsContainer) return;
        const query = patientSearchInput.value.trim();
        if (!query) {
            searchResultsContainer.innerHTML = '<div class="search-empty-state">検索キーワード（患者ID・氏名）を入力してください。</div>';
            if (searchResultCount) searchResultCount.textContent = "";
            return;
        }

        searchResultsContainer.innerHTML = `
            <div class="search-loading-spinner">
                <div class="spinner"></div>
                <span>データベース（gazouDB）を検索中...</span>
            </div>
        `;
        if (searchResultCount) searchResultCount.textContent = "検索中...";
        selectedCandidateIndex = -1;

        const formData = new URLSearchParams();
        formData.append("action", "search_patient");
        formData.append("query", query);

        fetch("index.php", {
            method: "POST",
            body: formData
        })
        .then(async response => {
            if (!response.ok) {
                const text = await response.text();
                throw new Error("HTTPエラー (" + response.status + "): " + text);
            }
            return response.json();
        })
        .then(data => {
            if (data.status === "success") {
                searchCandidates = data.data || [];
                renderSearchResults(searchCandidates);
            } else {
                if (searchResultCount) searchResultCount.textContent = "エラー";
                searchResultsContainer.innerHTML = `<div class="search-empty-state" style="color:#b91c1c;">${escapeHtml(data.message)}</div>`;
            }
        })
        .catch(err => {
            if (searchResultCount) searchResultCount.textContent = "通信エラー";
            searchResultsContainer.innerHTML = `<div class="search-empty-state" style="color:#b91c1c;">通信エラーが発生しました:<br>${escapeHtml(err.message)}</div>`;
        });
    }

    function renderSearchResults(list) {
        if (!searchResultsContainer) return;
        if (!list || list.length === 0) {
            if (searchResultCount) searchResultCount.textContent = "0 件該当";
            searchResultsContainer.innerHTML = '<div class="search-empty-state">該当する患者は見つかりませんでした。<br><small style="color:#94a3b8;">別のキーワード（IDや読みがな等）をお試しください。</small></div>';
            return;
        }

        if (searchResultCount) searchResultCount.textContent = `${list.length} 件該当（上限50件）`;
        let html = '';
        list.forEach((p, idx) => {
            const yomiDisplay = p.kanja_yomi ? `（${escapeHtml(p.kanja_yomi)}）` : '';
            const latestDisplay = p.latest_date ? `📅 最新: ${escapeHtml(p.latest_date)}` : '';
            html += `
                <div class="patient-candidate-item" data-index="${idx}" data-id="${escapeHtml(p.id)}" onclick="selectPatient('${escapeHtml(p.id)}')">
                    <div class="patient-candidate-main">
                        <span class="patient-id-badge">ID: ${escapeHtml(p.id)}</span>
                        <div class="patient-name-box">
                            <span class="patient-kanji">${escapeHtml(p.kanja_kanji || '（氏名未登録）')}</span>
                            <span class="patient-yomi">${yomiDisplay}</span>
                        </div>
                    </div>
                    <div class="patient-candidate-meta">
                        ${latestDisplay ? `<span class="patient-date">${latestDisplay}</span>` : ''}
                        <span class="patient-count-badge">${p.exam_count} 件の検査</span>
                        <a href="canvas_view.php?patient_id=${encodeURIComponent(p.id)}" class="badge" onclick="event.stopPropagation()" style="background:#1e3a8a; color:#93c5fd; padding:3px 8px; border-radius:4px; text-decoration:none; font-size:0.75rem;" title="この患者の検査キャンバスを開く">🗺️ キャンバス</a>
                        <span class="patient-select-arrow">→</span>
                    </div>
                </div>
            `;
        });
        searchResultsContainer.innerHTML = html;

        if (list.length === 1) {
            selectedCandidateIndex = 0;
            updateCandidateHighlight();
        }
    }

    function selectPatient(patientId) {
        if (!patientId) return;
        window.location.href = `index.php?patient_id=${encodeURIComponent(patientId)}&mode=${encodeURIComponent(currentViewMode)}`;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ==============================================================================
    // 🏥 ブラウザ内蔵・高速DICOMビューワ連携 & コンテキスト制御
    // ==============================================================================
    let currentDcmTarget = null;
    let dcmLongPressTimer = null;
    let dcmLongPressTriggered = false;

    function showToast(msg) {
        const toast = document.getElementById("toast");
        if (!toast) return;
        if (msg) toast.innerHTML = msg;
        toast.className = "show";
        setTimeout(() => { toast.className = toast.className.replace("show", ""); }, 3000);
    }

    function directLaunch(folderPath, launchMode) {
        const formData = new URLSearchParams();
        formData.append('action', 'launch');
        formData.append('folder', folderPath);
        formData.append('launch_mode', launchMode || 'NORMAL');

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            if (!response.ok) {
                const text = await response.text();
                throw new Error("HTTPエラー (" + response.status + "):\n" + text);
            }
            return response.json();
        })
        .then(data => {
            if (data.status === 'success') {
                showToast("🚀 外部ビューワ起動トリガー送信完了");
            } else {
                alert("アプリ側エラー: " + data.message);
            }
        })
        .catch(err => {
            alert("通信・PHPエラー詳細:\n" + err.message);
        });
    }

    // --------------------------------------------------------------------------
    // 🏥 DICOM & 📈 心電図(ECG) 高速ビューワー起動連携
    // --------------------------------------------------------------------------

    // URL生成ヘルパー (DICOMと心電図で自動分岐)
    function buildViewerUrl(target, mode) {
        const isEcg = (target.isEcg === "1" || target.isEcg === 1 || target.isEcg === true);
        const p = new URLSearchParams();
        p.set('id', target.id || '');
        p.set('name', target.name || '');
        p.set('kensa', target.kensa || '');
        p.set('date', target.date || '');
        p.set('sindan', target.sindan || '');
        p.set('mode', mode || 'standalone');

        if (isEcg) {
            p.set('file', target.folder || target.file || '');
            return `ecg_view.php?${p.toString()}`;
        } else {
            p.set('folder', target.folder || '');
            return `dicom_view.php?${p.toString()}`;
        }
    }

    // 後方互換性エイリアス
    function buildDicomViewUrl(target, mode) {
        return buildViewerUrl(target, mode);
    }

    // 1クリック時の通常モーダル表示 (DICOM / ECG共通エントリー)
    function openViewerFromButton(btn) {
        if (dcmLongPressTriggered) {
            dcmLongPressTriggered = false;
            return;
        }
        if (!btn) return;
        const target = extractTargetFromBtn(btn);
        openViewerModal(target);
    }

    function openDicomFromButton(btn) {
        openViewerFromButton(btn);
    }

    // モーダルを開く
    function openViewerModal(target) {
        const modal = document.getElementById('dicomViewerModal');
        const iframe = document.getElementById('dicomViewerIframe');
        if (!modal || !iframe) return;

        iframe.src = buildViewerUrl(target, 'modal');
        modal.style.display = 'flex';
    }

    function openDicomModal(target) {
        openViewerModal(target);
    }

    // モーダルを閉じる
    function closeViewerModal() {
        const modal = document.getElementById('dicomViewerModal');
        const iframe = document.getElementById('dicomViewerIframe');
        if (modal) modal.style.display = 'none';
        if (iframe) iframe.src = 'about:blank';
    }

    function closeDicomViewer() {
        closeViewerModal();
    }

    // iframeからのメッセージ（Escキー押下や閉じるボタン）を受信
    window.addEventListener('message', e => {
        if (e.data && (e.data.action === 'closeDicom' || e.data.action === 'closeECG')) {
            closeViewerModal();
        }
    });

    // --------------------------------------------------------------------------
    // 閲覧ボタンの長押し & 右クリック・コンテキストメニュー
    // --------------------------------------------------------------------------
    function extractTargetFromBtn(btn) {
        return {
            folder: btn.getAttribute('data-folder') || btn.getAttribute('data-file') || '',
            file:   btn.getAttribute('data-file') || btn.getAttribute('data-folder') || '',
            name:   btn.getAttribute('data-name') || '',
            id:     btn.getAttribute('data-id') || '',
            kensa:  btn.getAttribute('data-kensa') || '',
            date:   btn.getAttribute('data-date') || '',
            sindan: btn.getAttribute('data-sindan') || '',
            isEcg:  btn.getAttribute('data-is-ecg') || '0'
        };
    }

    function showDicomContextMenu(x, y, target) {
        currentDcmTarget = target;
        const menu = document.getElementById('dicomContextMenu');
        const patInfo = document.getElementById('ctxPatInfo');
        const examInfo = document.getElementById('ctxExamInfo');
        const modalLabel = document.getElementById('ctxModalLabel');
        if (!menu) return;

        const isEcg = (target.isEcg === "1" || target.isEcg === 1 || target.isEcg === true);

        if (patInfo) patInfo.textContent = (target.name || '患者') + ' (' + (target.id || '--') + ')';
        if (examInfo) {
            examInfo.textContent = (isEcg ? '📈 ' : '🏥 ') + (target.kensa || '');
            examInfo.style.color = isEcg ? '#34d399' : '#38bdf8';
        }
        if (modalLabel) {
            modalLabel.textContent = isEcg ? '📈 心電図ビューワー (モーダル)' : '🏥 DICOMビューワー (モーダル)';
        }

        // 画面外にはみ出さないよう座標調整
        menu.style.display = 'flex';
        const menuW = menu.offsetWidth || 230;
        const menuH = menu.offsetHeight || 140;
        const posX = (x + menuW > window.innerWidth) ? (window.innerWidth - menuW - 10) : x;
        const posY = (y + menuH > window.innerHeight) ? (y - menuH) : y;

        menu.style.left = posX + 'px';
        menu.style.top = posY + 'px';
    }

    // 右クリックでコンテキストメニュー
    document.addEventListener('contextmenu', e => {
        const btn = e.target.closest('.btn-inline-view');
        if (!btn) return;
        e.preventDefault();
        const target = extractTargetFromBtn(btn);
        showDicomContextMenu(e.clientX, e.clientY, target);
    });

    // 長押し判定 (500ms)
    document.addEventListener('pointerdown', e => {
        const btn = e.target.closest('.btn-inline-view');
        if (!btn || e.button !== 0) return;

        dcmLongPressTriggered = false;
        const target = extractTargetFromBtn(btn);

        dcmLongPressTimer = setTimeout(() => {
            dcmLongPressTriggered = true;
            showDicomContextMenu(e.clientX, e.clientY, target);
        }, 500);
    });

    document.addEventListener('pointerup', () => {
        if (dcmLongPressTimer) {
            clearTimeout(dcmLongPressTimer);
            dcmLongPressTimer = null;
        }
    });

    document.addEventListener('pointermove', e => {
        if (dcmLongPressTimer) {
            clearTimeout(dcmLongPressTimer);
            dcmLongPressTimer = null;
        }
    });

    // メニュー外クリックで閉じる
    document.addEventListener('click', e => {
        const menu = document.getElementById('dicomContextMenu');
        if (menu && !menu.contains(e.target)) {
            menu.style.display = 'none';
        }
    });

    // コンテキストメニュー選択時のアクション実行
    function execDicomContextAction(action) {
        const menu = document.getElementById('dicomContextMenu');
        if (menu) menu.style.display = 'none';
        if (!currentDcmTarget) return;

        if (action === 'modal') {
            openViewerModal(currentDcmTarget);
        } else if (action === 'tab') {
            const url = buildViewerUrl(currentDcmTarget, 'standalone');
            window.open(url, '_blank');
        } else if (action === 'window') {
            const url = buildViewerUrl(currentDcmTarget, 'standalone');
            window.open(url, '_blank', 'width=1600,height=900,menubar=no,toolbar=no,location=no,status=no');
        }
    }
    </script>
</body>
</html>
