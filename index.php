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

// デフォルトを本日の日付（YYYY/MM/DD）に設定
$input_date = $_GET['date'] ?? date('Y/m/d');
$target_date = str_replace('-', '/', $input_date);

$raw_filter_id = $_GET['patient_id'] ?? '';
// 5桁未満等で先頭が0パディングされている場合の除去（例: 08920 -> 8920）
$filter_id     = preg_replace('/^0+([0-9]+)$/', '$1', trim($raw_filter_id));
// デフォルトモードを 'custom' （カスタムビューワモード）に設定
$view_mode   = $_GET['mode'] ?? 'custom';

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

    $sql = "SELECT renban, id, kanja_kanji, kanja_yomi, kensamei, date, sindan1, folder, sex, age FROM gazou_tbl WHERE 1=1";
    $params = [];

    if (!empty($filter_id)) {
        $sql .= " AND (id = ? OR id = ?)";
        $params[] = $filter_id;
        $params[] = trim($raw_filter_id);
    } else {
        $sql .= " AND date LIKE ?";
        $params[] = $target_date . '%';
    }

    $sql .= " ORDER BY id DESC, renban DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_results = $stmt->fetchAll();

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

        $results[] = [
            'renban'      => $row['renban'],
            'id'          => $row['id'],
            'kanja_kanji' => mb_convert_encoding($row['kanja_kanji'] ?? '', 'UTF-8', 'CP932'),
            'kanja_yomi'  => trim(mb_convert_encoding($row['kanja_yomi'] ?? '', 'UTF-8', 'CP932')),
            'kensamei'    => $display_kensamei,
            'raw_kensamei'=> $raw_kensamei,
            'is_ecg'      => $is_ecg,
            'date'        => $row['date'],
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
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --bg-color: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --border-color: #e5e7eb;
            --cell-padding-y: 5px;
            --cell-padding-x: 10px;
            --font-size: 0.85rem;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        header {
            background: var(--card-bg);
            padding: 15px 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        /* タイトルエリア（行を変えて導入マニュアルリンクを配置） */
        .title-area {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        h1 { font-size: 1.2rem; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 8px; }
        .sub-help-link {
            font-size: 0.75rem;
            color: #94a3b8;
            text-decoration: none;
            transition: color 0.2s;
            display: inline-block;
        }
        .sub-help-link:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }

        .controls { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .btn {
            background-color: #e0e7ff; color: var(--primary-color); border: none;
            padding: 6px 12px; border-radius: 6px; font-weight: 500; cursor: pointer;
            text-decoration: none; font-size: 0.85rem; transition: background 0.2s;
        }
        .btn:hover { background-color: #c7d2fe; }
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-primary:hover { background-color: var(--primary-hover); }
        .btn-download { background-color: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; font-weight: 600; }
        .btn-download:hover { background-color: #dcfce7; }
        
        /* 常駐スクリプトDL横のコンパクトなヘルプ（？）ボタン */
        .btn-help {
            background-color: #fef3c7; color: #d97706; border: 1px solid #fde68a; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; font-size: 0.9rem; transition: background 0.2s;
        }
        .btn-help:hover { background-color: #fde68a; }

        input[type="date"], input[type="text"], select {
            padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.85rem; outline: none; background: #fff;
        }
        
        .filter-panel {
            background: var(--card-bg);
            padding: 12px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .filter-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .filter-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            min-width: 90px;
        }
        .toggle-btn {
            background-color: #f1f5f9;
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .toggle-btn:hover { background-color: #e2e8f0; }
        .toggle-btn.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .card { background: var(--card-bg); border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: var(--font-size); }
        th, td { padding: var(--cell-padding-y) var(--cell-padding-x); border-bottom: 1px solid var(--border-color); transition: padding 0.2s; }
        th { background-color: #f8fafc; font-weight: 600; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; cursor: pointer; }
        th:hover { color: var(--primary-color); background-color: #f1f5f9; }
        tr:hover { background-color: #f8fafc; }
        
        .badge {
            display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;
            background-color: #e0f2fe; color: #0369a1; text-decoration: none; transition: background 0.2s;
        }
        .badge:hover { background-color: #bae6fd; }
        .badge-kensamei { background-color: #eff6ff; color: #1d4ed8; }
        .badge-ecg { background-color: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8; }
        .badge-ecg:hover { background-color: #fce7f3; }

        .badge-custom { background-color: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; cursor: pointer; display: inline-block; padding: 4px 8px; border-radius: 4px; font-weight: 600; user-select: none; }
        .badge-custom:hover { background-color: #dcfce7; }

        .badge-jpeg { background-color: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; cursor: pointer; display: inline-block; padding: 4px 8px; border-radius: 4px; font-weight: 600; user-select: none; }
        .badge-jpeg:hover { background-color: #ffedd5; }

        .patient-link { color: var(--primary-color); font-weight: 600; text-decoration: none; }
        .patient-link:hover { text-decoration: underline; }
        
        .karte-link {
            font-family: monospace;
            background-color: #f1f5f9;
            padding: 3px 6px;
            border-radius: 4px;
            color: #1e293b;
            text-decoration: none;
            border: 1px solid var(--border-color);
            display: inline-block;
            transition: all 0.2s;
        }
        .karte-link:hover {
            background-color: #e2e8f0;
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .folder-click {
            color: #0d9488;
            cursor: pointer;
            font-weight: 500;
            text-decoration: underline;
            text-decoration-style: dotted;
        }
        .folder-click:hover {
            color: #0f766e;
            background-color: #ccfbf1;
        }

        .reset-filter { background-color: #fee2e2; color: #991b1b; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; text-decoration: none; font-weight: 500; }
        .reset-filter:hover { background-color: #fecaca; }
        .error-box { background-color: #fee2e2; color: #b91c1c; padding: 15px; border-radius: 8px; margin-bottom: 20px; }

        #toast {
            visibility: hidden;
            min-width: 280px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 12px;
            position: fixed;
            z-index: 1000;
            left: 50%;
            bottom: 30px;
            transform: translateX(-50%);
            font-size: 0.9rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        }
        #toast.show { visibility: visible; animation: fadein 0.5s, fadeout 0.5s 2.5s; }
        @keyframes fadein { from { bottom: 0; opacity: 0; } to { bottom: 30px; opacity: 1; } }
        @keyframes fadeout { from { bottom: 30px; opacity: 1; } to { bottom: 0; opacity: 0; } }
    </style>
</head>
<body>

    <div class="container">
        <!-- ヘッダー -->
        <header>
            <div class="title-area">
                <h1>🏥 RS-Base 検査・画像ビューア</h1>
                <a href="igankenshin.html" target="_blank" class="sub-help-link" title="胃がん検診データ作成補助機能 導入マニュアルを開きます">
                    胃がん検診データ作成補助機能 導入マニュアル
                </a>
            </div>

            <div class="controls">
                <div style="display:flex; align-items:center; gap:5px; background:#e2e8f0; padding:3px 8px; border-radius:6px;">
                    <span style="font-size:0.75rem; font-weight:600; color:#475569;">モード:</span>
                    <select onchange="changeViewMode(this.value)" style="border:none; background:transparent; font-weight:600; color:#1e293b; cursor:pointer; outline:none;">
                        <option value="rsbase" <?= $view_mode === 'rsbase' ? 'selected' : '' ?>>RSBaseモード (標準)</option>
                        <option value="custom" <?= $view_mode === 'custom' ? 'selected' : '' ?>>カスタムビューワモード (直起動)</option>
                        <option value="jpeg"   <?= $view_mode === 'jpeg' ? 'selected' : '' ?>>胃がん検診JPEG変換モード</option>
                    </select>
                </div>

                <?php if (!empty($filter_id)): ?>
                    <a href="index.php?date=<?= htmlspecialchars($target_date, ENT_QUOTES, 'UTF-8') ?>&mode=<?= $view_mode ?>" class="reset-filter">👤 患者絞り込みを解除</a>
                <?php endif; ?>

                <?php if (empty($filter_id)): ?>
                    <div style="display:flex; align-items:center; gap:5px;">
                        <a href="index.php?date=<?= $prev_date ?>&mode=<?= $view_mode ?>" class="btn">◀ 前日</a>
                        <form method="GET" action="index.php" style="display:flex; gap:5px; align-items:center;">
                            <input type="hidden" name="mode" value="<?= htmlspecialchars($view_mode, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="date" name="date" value="<?= htmlspecialchars($calendar_date, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()">
                        </form>
                        <a href="index.php?date=<?= $next_date ?>&mode=<?= $view_mode ?>" class="btn">翌日 ▶</a>
                    </div>
                <?php endif; ?>

                <?php if ($view_mode === 'custom' || $view_mode === 'jpeg'): ?>
                    <a href="watcher.zip" class="btn btn-download" download title="常駐監視スクリプト（watcher.zip）をダウンロードします">
                        📥 常駐スクリプトDL (.zip)
                    </a>
                    <a href="help.html" target="_blank" class="btn-help" title="ヘルプを開きます">
                        ❓
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- フィルタパネル -->
        <div class="filter-panel">
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

        <?php if (!empty($error_message)): ?>
            <div class="error-box"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
            <div class="card">
                <table id="dataTable">
                    <thead>
                        <tr>
                            <th style="width: 80px;">連番 (編集)</th>
                            <th onclick="sortTable('kensa')">検査名 <span id="sort-kensa">↕</span></th>
                            <th style="width: 110px;">検査日</th>
                            <th style="width: 110px;" onclick="sortTable('id')">検査ID <span id="sort-id">▼</span></th>
                            <th onclick="sortTable('yomi')">患者氏名 (よみ順) <span id="sort-yomi">↕</span></th>
                            <th style="width: 110px;">年齢 / 性別</th>
                            <th>診断・所見</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($results) > 0): ?>
                            <?php foreach ($results as $row): ?>
                            <?php 
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
                            <tr data-kensamei="<?= htmlspecialchars($row['kensamei'], ENT_QUOTES, 'UTF-8') ?>" 
                                data-yomi="<?= htmlspecialchars($row['kanja_yomi'], ENT_QUOTES, 'UTF-8') ?>"
                                data-id="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <td>
                                    <a href="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="badge" title="本家の編集・カスタマイズ画面を開く">
                                        <?= htmlspecialchars($row['renban'], ENT_QUOTES, 'UTF-8') ?> ✏️
                                    </a>
                                </td>
                                <td>
                                    <?= $badge_html ?>
                                </td>
                                <td><?= htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <a href="<?= htmlspecialchars($karte_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="karte-link" title="カルテビューワを開く">
                                        <?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?> 📋
                                    </a>
                                </td>
                                <td>
                                    <a href="index.php?patient_id=<?= urlencode($row['id']) ?>&mode=<?=$view_mode ?>" class="patient-link" title="この患者の全画像を絞り込み">
                                        <?= htmlspecialchars($row['kanja_kanji'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                    <small style="color:var(--text-muted); display:block; font-size:0.7rem;"><?= htmlspecialchars($row['kanja_yomi'], ENT_QUOTES, 'UTF-8') ?></small>
                                </td>
                                <td>
                                    <!-- 年齢部分：クリック時は検査種類に関係なく常に "JPEG" モードでパス複写＆トリガー起動 -->
                                    <span class="folder-click" onclick='copyAndLaunch(this, <?= json_encode($unc_folder_display, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, "JPEG")' title="クリックしてDICOMパスをコピー＆JPEG変換起動">
                                        <?= htmlspecialchars($row['age'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                    </span> 
                                    / <?= htmlspecialchars($row['sex'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="color: var(--text-muted);"><?= htmlspecialchars($row['sindan1'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="noDataRow">
                                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 40px;">該当するデータはありません。</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div id="toast">フォルダパスをコピーしました。<br>JPEG変換トリガーを送信しました。</div>

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
    }
    </script>

</body>
</html>
