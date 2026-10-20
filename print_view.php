<?php
// ==============================================================================
// RS-Base 2×4 検査横断画像比較・印刷システム (print_view.php)
// A4縦 2列×4行 (8画像) 隙間なし最大化 ＋ 直感ドラッグ＆ズーム・トリミング
// 病巣強調表示 (IEE: LCI / NBI / TXI / 色素散布 等) ＋ 同一画像複製・一部拡大比較
// ==============================================================================

$host     = '192.168.1.164';
$dbname   = 'gazouDB';
$user     = 'postgres';
$password = 'medicalin';
$port     = '5432';

$raw_patient_id = $_GET['patient_id'] ?? $_GET['id'] ?? '';
$patient_id = preg_replace('/^0+([0-9]+)$/', '$1', trim($raw_patient_id));
$passed_name = $_GET['name'] ?? '';

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

$patient_info = null;
$exam_list = [];
$db_error = '';

if (!empty($patient_id)) {
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;connect_timeout=2;";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $pdo->exec("SET client_encoding TO 'UTF8'");

        // 患者基本情報取得
        $sqlPat = "SELECT g.id, g.kanja_kanji, g.kanja_yomi, g.sex, g.age, n.birth
                   FROM gazou_tbl g
                   LEFT JOIN name_tbl n ON g.id = CAST(n.id AS character varying)
                   WHERE g.id = ? OR g.id = ?
                   LIMIT 1";
        $stmtPat = $pdo->prepare($sqlPat);
        $stmtPat->execute([$patient_id, $raw_patient_id]);
        $patRow = $stmtPat->fetch();

        if ($patRow) {
            $calc_age = $patRow['age'] ?? '';
            if (empty($calc_age) && !empty($patRow['birth'])) {
                try {
                    $b = str_replace(['-', '.'], '/', trim($patRow['birth']));
                    $dt_b = new DateTime($b);
                    $dt_now = new DateTime();
                    $calc_age = (string)$dt_b->diff($dt_now)->y;
                } catch (Exception $e) {}
            }

            $patient_info = [
                'id'          => $patRow['id'],
                'kanja_kanji' => trim($patRow['kanja_kanji'] ?? ''),
                'kanja_yomi'  => trim($patRow['kanja_yomi'] ?? ''),
                'sex'         => trim($patRow['sex'] ?? ''),
                'age'         => $calc_age,
                'birth'       => trim($patRow['birth'] ?? ''),
                'birth_wareki'=> format_wareki_birth($patRow['birth'] ?? '')
            ];
        }

        // 過去の全検査リスト取得 (フォルダが存在するもの)
        $sqlExams = "SELECT renban, kensamei, date, folder, sindan1, doctor
                     FROM gazou_tbl
                     WHERE (id = ? OR id = ?) AND folder IS NOT NULL AND folder != ''
                     ORDER BY date DESC, renban DESC";
        $stmtExams = $pdo->prepare($sqlExams);
        $stmtExams->execute([$patient_id, $raw_patient_id]);
        $rows = $stmtExams->fetchAll();

        foreach ($rows as $r) {
            $raw_kensa = trim($r['kensamei'] ?? '');
            $category = 'other';
            $cat_label = '一般';
            $badge_color = '#64748b';

            if (preg_match('/内視鏡|胃|大腸|十二指腸|食道|直腸|結腸|咽頭|喉頭|気管支|GF|CF|IF|BF|ES|カメラ|スコープ|ESD|EMR|ERCP|生検/iu', $raw_kensa)) {
                $category = 'endoscopy';
                $cat_label = '内視鏡';
                $badge_color = '#10b981';
            } elseif (preg_match('/エコー|超音波|US|Adult_Abd/iu', $raw_kensa)) {
                $category = 'us';
                $cat_label = '超音波';
                $badge_color = '#0284c7';
            } elseif (preg_match('/XP|CR|DR|胸部|レントゲン|単純|RF|透視|マンモ|腰椎|腹部(D)|骨/iu', $raw_kensa)) {
                $category = 'xray';
                $cat_label = 'XP単純';
                $badge_color = '#8b5cf6';
            } elseif (preg_match('/心電図|ECG/iu', $raw_kensa)) {
                $category = 'ecg';
                $cat_label = '心電図';
                $badge_color = '#ef4444';
            }

            $raw_folder = $r['folder'] ?? '';
            $unc_folder = str_replace('/', '\\', $raw_folder);

            // 心電図ファイル直接パスの場合は除外（画像印刷対象：エコー・内視鏡・XP）
            if ($category === 'ecg' || str_ends_with(strtolower($unc_folder), '.ecg')) {
                continue;
            }

            $clean_date = preg_replace('/^(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}).*$/', '$1', trim($r['date']));

            $exam_list[] = [
                'renban'      => $r['renban'],
                'kensamei'    => $raw_kensa,
                'category'    => $category,
                'cat_label'   => $cat_label,
                'badge_color' => $badge_color,
                'date'        => $clean_date,
                'raw_date'    => $r['date'],
                'folder'      => $unc_folder,
                'sindan'      => trim($r['sindan1'] ?? ''),
                'doctor'      => trim($r['doctor'] ?? '')
            ];
        }

    } catch (Exception $e) {
        $db_error = $e->getMessage();
    }
}

// フォールバック（DB未接続または患者指定なし時）
if (!$patient_info) {
    $patient_info = [
        'id'          => $patient_id ?: '00000',
        'kanja_kanji' => $passed_name ?: '患者未指定',
        'kanja_yomi'  => '',
        'sex'         => '',
        'age'         => '',
        'birth'       => '',
        'birth_wareki'=> ''
    ];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2×4 画像印刷 - <?= htmlspecialchars($patient_info['kanja_kanji'] ? "{$patient_info['kanja_kanji']} 様" : "RS-Base", ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --bg-workspace: #090d16;
            --bg-toolbar: #0f172a;
            --border-color: #334155;
            --accent-green: #10b981;
            --accent-blue: #38bdf8;
            --sheet-width: 210mm;
            --sheet-height: 297mm;
            --sheet-zoom: 1;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            width: 100%;
            height: 100%;
            background-color: var(--bg-workspace);
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", "Hiragino Kaku Gothic ProN", "Meiryo", sans-serif;
            overflow-x: hidden;
            overflow-y: auto;
        }

        /* --------------------------------------------------------------------------
           上部固定ツールバー (画面操作専用・印刷時は非表示)
        -------------------------------------------------------------------------- */
        .workspace-toolbar {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            height: 48px;
            background: linear-gradient(180deg, #111827 0%, #0b0f19 100%);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        }

        .toolbar-left, .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toolbar-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            font-weight: 700;
            color: #e2e8f0;
        }

        .tool-btn {
            background: #1e293b;
            color: #f1f5f9;
            border: 1px solid var(--border-color);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .tool-btn:hover {
            background: #334155;
            border-color: #64748b;
        }

        .tool-btn-primary {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            border-color: #10b981;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.35);
        }

        .tool-btn-primary:hover {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.5);
            transform: translateY(-1px);
        }

        .tool-btn-danger {
            background: rgba(239, 68, 68, 0.15);
            border-color: #ef4444;
            color: #fca5a5;
        }

        .tool-btn-danger:hover {
            background: #dc2626;
            color: #ffffff;
        }

        .scale-control {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            color: #94a3b8;
        }

        .scale-control input[type="range"] {
            width: 90px;
            cursor: pointer;
            accent-color: var(--accent-green);
        }

        /* --------------------------------------------------------------------------
           メインワークスペース（用紙表示ステージ）
        -------------------------------------------------------------------------- */
        .workspace-stage {
            display: flex;
            justify-content: center;
            padding: 24px 16px 40px;
            min-height: calc(100vh - 48px);
        }

        /* --------------------------------------------------------------------------
           A4縦 印刷シートコンテナ
        -------------------------------------------------------------------------- */
        .print-sheet {
            width: var(--sheet-width);
            height: var(--sheet-height);
            background-color: #ffffff;
            color: #0f172a;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            padding: 5mm; /* 用紙余白 最小限 (5mm) */
            display: flex;
            flex-direction: column;
            transform-origin: top center;
            transform: scale(var(--sheet-zoom));
            transition: transform 0.1s ease-out;
            position: relative;
            flex-shrink: 0;
        }

        /* 患者情報ヘッダー (最上部) */
        .sheet-header {
            height: 20px;
            min-height: 20px;
            border-bottom: 1.5px solid #0f172a;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 4px 2px;
            font-size: 11px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 3px;
            line-height: 1;
            flex-shrink: 0;
        }

        .pat-main-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pat-id-tag {
            font-family: monospace;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .pat-name-text {
            font-size: 12.5px;
            font-weight: 700;
        }

        .pat-sub-info {
            font-size: 10.5px;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sheet-meta-info {
            font-size: 10px;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* --------------------------------------------------------------------------
           2×4 画像グリッド (横2列 × 縦4行 ＝ 8枠)
        -------------------------------------------------------------------------- */
        .sheet-grid {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(4, 1fr);
            gap: 3.5px; /* 紙面余白を詰めて画像を最大化 */
            min-height: 0;
        }

        /* 各画像セル枠 */
        .grid-cell {
            border: 1px solid #94a3b8;
            border-radius: 2px;
            background: #000000;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            min-height: 0;
        }

        /* 画像表示ビューポート (ドラッグ＆ズーム領域) */
        .cell-viewport {
            flex: 1;
            min-height: 0;
            position: relative;
            overflow: hidden;
            background: #000000;
            cursor: grab;
            user-select: none;
            -webkit-user-select: none;
        }

        .cell-viewport.grabbing {
            cursor: grabbing;
        }

        .cell-viewport img {
            width: 100%;
            height: 100%;
            object-fit: cover; /* 隙間なく枠いっぱいに表示 */
            display: block;
            pointer-events: none;
            transform-origin: center center;
            will-change: transform;
        }

        /* 各枠の上部フローティング操作ボタン (画面操作専用・印刷時非表示) */
        .cell-tools {
            position: absolute;
            top: 3px;
            right: 3px;
            display: flex;
            align-items: center;
            gap: 3px;
            background: rgba(15, 23, 42, 0.88);
            backdrop-filter: blur(4px);
            padding: 2px 4px;
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            opacity: 0;
            transition: opacity 0.15s ease-in-out;
            z-index: 10;
        }

        .grid-cell:hover .cell-tools {
            opacity: 1;
        }

        .cell-tool-btn {
            background: transparent;
            border: none;
            color: #f1f5f9;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 5px;
            border-radius: 3px;
            cursor: pointer;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 2px;
        }

        .cell-tool-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            color: var(--accent-blue);
        }

        .cell-tool-btn.btn-del:hover {
            background: #ef4444;
            color: #ffffff;
        }

        .cell-tool-btn.btn-highlight {
            color: #38bdf8;
            font-weight: 800;
        }

        /* 枠番号バッジ (画面操作用) */
        .cell-badge {
            position: absolute;
            top: 3px;
            left: 3px;
            background: rgba(0, 0, 0, 0.75);
            color: #e2e8f0;
            font-family: monospace;
            font-size: 9.5px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            pointer-events: none;
            z-index: 9;
        }

        /* 病巣強調モード表示バッジ (画面＆紙面表示) */
        .cell-iee-badge {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: rgba(15, 23, 42, 0.88);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #f8fafc;
            font-size: 9px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
            z-index: 8;
            pointer-events: none;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.6);
        }

        /* 病巣強調モード選択用ポップオーバー */
        .iee-popover {
            position: absolute;
            top: 26px;
            right: 3px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.75);
            padding: 4px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            z-index: 50;
            min-width: 145px;
        }

        .iee-popover-item {
            background: transparent;
            border: none;
            color: #e2e8f0;
            padding: 5px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-align: left;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.12s;
        }

        .iee-popover-item:hover {
            background: #1e293b;
            color: #38bdf8;
        }

        .iee-popover-item.active {
            background: #0284c7;
            color: #ffffff;
            font-weight: 700;
        }

        /* 空き枠表示 */
        .cell-empty {
            flex: 1;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: #94a3b8;
            cursor: pointer;
            border: 1.5px dashed #cbd5e1;
            margin: 2px;
            border-radius: 3px;
            transition: all 0.15s;
        }

        .cell-empty:hover {
            background: #f1f5f9;
            border-color: #0284c7;
            color: #0284c7;
        }

        .cell-empty-icon {
            font-size: 1.5rem;
            opacity: 0.7;
        }

        .cell-empty-text {
            font-size: 11px;
            font-weight: 600;
        }

        /* 画像下部キャプション枠 (検査名・撮影日時・所見メモ) */
        .cell-caption {
            height: 17px;
            min-height: 17px;
            background: #ffffff;
            border-top: 1px solid #94a3b8;
            padding: 0 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 9.5px;
            font-weight: 600;
            color: #0f172a;
            flex-shrink: 0;
            line-height: 1;
            overflow: hidden;
            white-space: nowrap;
        }

        .caption-text {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            outline: none;
            cursor: text;
        }

        .caption-text:focus {
            background: #fef08a;
            border-radius: 2px;
        }

        /* --------------------------------------------------------------------------
           他検査画像ピッカー・スライドドロワー (画面専用・印刷時非表示)
        -------------------------------------------------------------------------- */
        .picker-drawer {
            position: fixed;
            top: 48px;
            right: 0;
            bottom: 0;
            width: 420px;
            background: #0f172a;
            border-left: 1px solid var(--border-color);
            box-shadow: -6px 0 20px rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            z-index: 90;
            transform: translateX(100%);
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .picker-drawer.open {
            transform: translateX(0);
        }

        .drawer-header {
            padding: 12px 14px;
            background: #111827;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .drawer-header h3 {
            font-size: 0.95rem;
            color: #f1f5f9;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .drawer-close-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 1.1rem;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .drawer-close-btn:hover {
            color: #ffffff;
            background: #1e293b;
        }

        .drawer-body {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .exam-item-card {
            background: #1e293b;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }

        .exam-item-header {
            padding: 8px 10px;
            background: rgba(255, 255, 255, 0.03);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
        }

        .exam-item-header:hover {
            background: rgba(255, 255, 255, 0.07);
        }

        .exam-item-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #f1f5f9;
        }

        .exam-item-badge {
            font-size: 0.68rem;
            padding: 1px 5px;
            border-radius: 3px;
            color: #ffffff;
            font-weight: 700;
        }

        .exam-item-date {
            font-size: 0.75rem;
            color: #94a3b8;
            font-family: monospace;
        }

        .exam-thumb-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            padding: 10px;
            background: #0b0f19;
            border-top: 1px solid var(--border-color);
        }

        .picker-thumb-card {
            position: relative;
            aspect-ratio: 4 / 3;
            background: #020617;
            border: 1px solid #334155;
            border-radius: 4px;
            overflow: hidden;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .picker-thumb-card:hover {
            border-color: var(--accent-blue);
            transform: scale(1.03);
            z-index: 5;
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.4);
        }

        .picker-thumb-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .picker-thumb-badge {
            position: absolute;
            bottom: 2px;
            right: 2px;
            background: rgba(0, 0, 0, 0.75);
            color: #ffffff;
            font-size: 9px;
            padding: 0 4px;
            border-radius: 2px;
            font-family: monospace;
        }

        /* トースト通知 */
        #printToast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(30px);
            background: rgba(15, 23, 42, 0.95);
            color: #f8fafc;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            opacity: 0;
            transition: all 0.25s ease;
            pointer-events: none;
            z-index: 200;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        #printToast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* --------------------------------------------------------------------------
           🖨️ A4縦 厳密印刷CSS (@media print)
        -------------------------------------------------------------------------- */
        @media print {
            @page {
                size: A4 portrait;
                margin: 4.5mm; /* 枠外余白を最小限にして画像を最大化 */
            }

            html, body {
                width: 100% !important;
                height: 100% !important;
                background: #ffffff !important;
                color: #000000 !important;
                overflow: hidden !important;
                padding: 0 !important;
                margin: 0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .no-print {
                display: none !important;
            }

            .workspace-stage {
                padding: 0 !important;
                margin: 0 !important;
                min-height: 0 !important;
                display: block !important;
            }

            .print-sheet {
                width: 100% !important;
                height: 100% !important;
                max-height: 288mm !important;
                padding: 0 !important;
                margin: 0 !important;
                box-shadow: none !important;
                border: none !important;
                transform: none !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
            }

            .sheet-header {
                height: 18px !important;
                min-height: 18px !important;
                border-bottom: 1.5px solid #000000 !important;
                color: #000000 !important;
                margin-bottom: 2.5px !important;
            }

            .sheet-grid {
                gap: 2.5px !important;
                height: calc(100% - 22px) !important;
            }

            .grid-cell {
                border: 1px solid #475569 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .cell-caption {
                height: 16px !important;
                min-height: 16px !important;
                border-top: 1px solid #475569 !important;
                color: #000000 !important;
                font-size: 9px !important;
            }

            .cell-badge, .cell-tools, .iee-popover {
                display: none !important;
            }

            .cell-iee-badge {
                border: 1px solid #000000 !important;
                background: rgba(255, 255, 255, 0.85) !important;
                color: #000000 !important;
                box-shadow: none !important;
            }

            .cell-empty {
                border: 1px dashed #cbd5e1 !important;
                background: #ffffff !important;
            }

            .cell-empty-icon, .cell-empty-text {
                opacity: 0.3 !important;
            }
        }
    </style>
</head>
<body>

    <!-- ----------------------------------------------------------------------
         画面上部ツールバー (印刷時は自動非表示)
    ----------------------------------------------------------------------- -->
    <header class="workspace-toolbar no-print">
        <div class="toolbar-left">
            <button type="button" class="tool-btn tool-btn-primary" id="btnPrintRun" title="A4縦 印刷プレビューを実行 [Ctrl+P]">
                🖨️ <strong>印刷する (A4縦)</strong>
            </button>
            <button type="button" class="tool-btn" id="btnToggleDrawer" title="過去の検査画像一覧から選んで追加">
                📂 検査横断ピッカー
            </button>
            <button type="button" class="tool-btn" id="btnResetAllSlots" title="全枠のドラッグ位置・ズームをリセット">
                🔄 全枠リセット
            </button>
            <button type="button" class="tool-btn tool-btn-danger" id="btnClearTray" title="トレイの画像をすべてクリア">
                🗑️ トレイ全消去
            </button>
        </div>

        <div class="toolbar-right">
            <div class="scale-control" title="画面プレビュー表示倍率">
                <span>🔍 表示:</span>
                <input type="range" id="zoomSheetRange" min="0.6" max="1.3" step="0.05" value="1.0">
                <span id="zoomSheetVal" style="font-family:monospace; min-width:38px;">100%</span>
            </div>
            <button type="button" class="tool-btn" id="btnClosePage" title="ビューワーに戻る">
                ✕ 閉じる
            </button>
        </div>
    </header>

    <!-- ----------------------------------------------------------------------
         用紙ステージ
    ----------------------------------------------------------------------- -->
    <main class="workspace-stage">
        <div class="print-sheet" id="printSheet">
            <!-- 最上部: 患者情報ヘッダー -->
            <div class="sheet-header">
                <div class="pat-main-info">
                    <span class="pat-id-tag">ID: <?= htmlspecialchars($patient_info['id'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="pat-name-text"><?= htmlspecialchars($patient_info['kanja_kanji'], ENT_QUOTES, 'UTF-8') ?> 様</span>
                    <?php if (!empty($patient_info['kanja_yomi'])): ?>
                        <span style="font-size:10px; color:#475569;">(<?= htmlspecialchars($patient_info['kanja_yomi'], ENT_QUOTES, 'UTF-8') ?>)</span>
                    <?php endif; ?>
                </div>

                <div class="pat-sub-info">
                    <?php if (!empty($patient_info['birth_wareki'])): ?>
                        <span>生年月日: <?= htmlspecialchars($patient_info['birth_wareki'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <?php if (!empty($patient_info['age'])): ?>
                        <span>(<?= htmlspecialchars($patient_info['age'], ENT_QUOTES, 'UTF-8') ?>歳)</span>
                    <?php endif; ?>
                    <?php if (!empty($patient_info['sex'])): ?>
                        <span><?= htmlspecialchars($patient_info['sex'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>

                <div class="sheet-meta-info">
                    <span>印刷日: <?= date('Y/m/d H:i') ?></span>
                    <strong style="color:#0f172a; font-weight:700;">RS-Base 2×4 印刷</strong>
                </div>
            </div>

            <!-- 2×4 グリッド (8スロット) -->
            <div class="sheet-grid" id="sheetGrid">
                <!-- JavaScriptで8つのスロットが動的生成されます -->
            </div>
        </div>
    </main>

    <!-- ----------------------------------------------------------------------
         他検査画像ピッカー (スライドイン・ドロワー)
    ----------------------------------------------------------------------- -->
    <aside class="picker-drawer no-print" id="pickerDrawer">
        <div class="drawer-header">
            <h3>📂 検査横断 画像ピッカー</h3>
            <button type="button" class="drawer-close-btn" id="btnDrawerClose">✕</button>
        </div>
        <div style="padding:8px 14px; background:#1e293b; border-bottom:1px solid #334155; font-size:0.78rem; color:#94a3b8;">
            過去の検査をクリックしてサムネイルを表示し、クリックで印刷枠（空きスロット）に追加できます。
        </div>
        <div class="drawer-body" id="drawerBody">
            <!-- 検査カードが動的挿入されます -->
        </div>
    </aside>

    <!-- SVGフィルター定義 (内視鏡画像強調用: GPUハードウェアアクセラレーション) -->
    <svg style="position: absolute; width: 0; height: 0; overflow: hidden; pointer-events: none;" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <!-- ① LCI風・発赤強調 -->
            <filter id="iee-lci" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    1.45 -0.25 -0.15 0 0.04
                   -0.10  1.15 -0.10 0 0.00
                   -0.15 -0.15  1.20 0 0.00
                    0     0     0    1 0" />
                <feComponentTransfer>
                    <feFuncR type="linear" slope="1.15" intercept="-0.04" />
                    <feFuncG type="linear" slope="1.05" intercept="-0.02" />
                    <feFuncB type="linear" slope="1.05" intercept="-0.02" />
                </feComponentTransfer>
            </filter>

            <!-- ② 疑似NBI風・微細血管/ピット強調 -->
            <filter id="iee-nbi" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    0.20  0.65  0.25  0 -0.05
                    0.00  0.75  0.35  0  0.00
                    0.00  0.20  0.85  0  0.05
                    0     0     0     1  0" />
                <feComponentTransfer>
                    <feFuncR type="linear" slope="1.25" intercept="-0.06" />
                    <feFuncG type="linear" slope="1.10" intercept="0.00" />
                    <feFuncB type="linear" slope="1.10" intercept="0.00" />
                </feComponentTransfer>
            </filter>

            <!-- ③ ヘモグロビン血流マップ (IHb) -->
            <filter id="iee-ihb" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    1.80 -0.80 -0.10 0 0.10
                   -0.40  1.40 -0.10 0 -0.05
                   -0.80 -0.20  1.80 0 -0.10
                    0     0     0    1 0" />
            </filter>

            <!-- ④ 凹凸・構造強調 (Texture) -->
            <filter id="iee-texture" color-interpolation-filters="sRGB">
                <feConvolveMatrix order="3" kernelMatrix="
                   -0.3 -0.6 -0.3
                   -0.6  4.6 -0.6
                   -0.3 -0.6 -0.3" preserveAlpha="true" />
            </filter>

            <!-- ⑤ 色素散布シミュレーション (インジゴカルミン風) -->
            <filter id="iee-indigo" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    0.85  0.00  0.00 0 -0.05
                    0.00  0.90  0.00 0 -0.02
                    0.10  0.20  1.30 0  0.10
                    0     0     0    1  0" />
                <feConvolveMatrix order="3" kernelMatrix="
                   -0.2 -0.3 -0.2
                   -0.3  3.0 -0.3
                   -0.2 -0.3 -0.2" preserveAlpha="true" />
            </filter>

            <!-- ⑥ TXI風・自然強調 -->
            <filter id="iee-txi" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    1.20 -0.12 -0.06 0  0.02
                   -0.06  1.14 -0.05 0  0.01
                   -0.05 -0.06  1.15 0  0.01
                    0     0     0    1  0" />
                <feConvolveMatrix order="3" kernelMatrix="
                   -0.2 -0.3 -0.2
                   -0.3  3.0 -0.3
                   -0.2 -0.3 -0.2" preserveAlpha="true" />
                <feComponentTransfer>
                    <feFuncR type="linear" slope="1.06" intercept="0.01" />
                    <feFuncG type="linear" slope="1.04" intercept="0.01" />
                    <feFuncB type="linear" slope="1.04" intercept="0.01" />
                </feComponentTransfer>
            </filter>

            <!-- ⑦ 褪色・平坦病変強調 (Pale) -->
            <filter id="iee-pale" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    0.78  0.18  0.06 0 -0.02
                   -0.12  1.22 -0.04 0  0.03
                    0.06 -0.16  1.38 0  0.05
                    0     0     0    1  0" />
                <feConvolveMatrix order="3" kernelMatrix="
                   -0.25 -0.4 -0.25
                   -0.4   3.6 -0.4
                   -0.25 -0.4 -0.25" preserveAlpha="true" />
                <feComponentTransfer>
                    <feFuncR type="linear" slope="0.95" intercept="0.02" />
                    <feFuncG type="linear" slope="1.10" intercept="-0.02" />
                    <feFuncB type="linear" slope="1.15" intercept="-0.03" />
                </feComponentTransfer>
            </filter>
        </defs>
    </svg>

    <!-- トースト通知 -->
    <div id="printToast" class="no-print">メッセージ</div>

    <!-- ----------------------------------------------------------------------
         JavaScriptロジック
    ----------------------------------------------------------------------- -->
    <script>
        const PATIENT_INFO = <?= json_encode($patient_info, JSON_UNESCAPED_UNICODE) ?>;
        const EXAM_LIST    = <?= json_encode($exam_list, JSON_UNESCAPED_UNICODE) ?>;
        const PRINT_TRAY_KEY = 'rsbase_print_tray';

        const sheetGrid = document.getElementById('sheetGrid');
        const printSheet = document.getElementById('printSheet');
        const pickerDrawer = document.getElementById('pickerDrawer');
        const btnToggleDrawer = document.getElementById('btnToggleDrawer');
        const btnDrawerClose = document.getElementById('btnDrawerClose');
        const drawerBody = document.getElementById('drawerBody');
        const zoomSheetRange = document.getElementById('zoomSheetRange');
        const zoomSheetVal = document.getElementById('zoomSheetVal');
        const btnPrintRun = document.getElementById('btnPrintRun');
        const btnResetAllSlots = document.getElementById('btnResetAllSlots');
        const btnClearTray = document.getElementById('btnClearTray');
        const btnClosePage = document.getElementById('btnClosePage');
        const toast = document.getElementById('printToast');

        // 8スロット分の内部データ (インデックス 0〜7)
        let slots = Array(8).fill(null);
        let activeTargetSlotIndex = null;

        // トースト表示ヘルパー
        function showToast(msg) {
            toast.textContent = msg;
            toast.className = 'show';
            setTimeout(() => { toast.className = ''; }, 2200);
        }

        // LocalStorageから印刷トレイ読み込み
        function loadTrayFromStorage() {
            try {
                const raw = localStorage.getItem(PRINT_TRAY_KEY);
                if (raw) {
                    const parsed = JSON.parse(raw);
                    if (parsed && Array.isArray(parsed.items)) {
                        slots = Array(8).fill(null);
                        parsed.items.slice(0, 8).forEach((item, idx) => {
                            slots[idx] = {
                                id: item.id || `slot_${idx}_${Date.now()}`,
                                folder: item.folder || '',
                                file: item.file || '',
                                examName: item.examName || '',
                                examDate: item.examDate || '',
                                caption: item.caption || `[${item.examName || '検査'}] ${item.examDate || ''} (${item.file || ''})`,
                                panX: item.panX || 0,
                                panY: item.panY || 0,
                                zoom: item.zoom || 1.0,
                                ieeMode: item.ieeMode || 'normal',
                                contrast: item.contrast || 100,
                                brightness: item.brightness || 100,
                                invert: !!item.invert
                            };
                        });
                        return;
                    }
                }
            } catch (err) {
                console.warn("Tray load error:", err);
            }
            slots = Array(8).fill(null);
        }

        // LocalStorageへ現在のスロット状態を保存
        function saveTrayToStorage() {
            try {
                const items = slots.filter(s => s !== null);
                const tray = {
                    patientId: PATIENT_INFO.id,
                    patientName: PATIENT_INFO.kanja_kanji,
                    updatedAt: Date.now(),
                    items: items
                };
                localStorage.setItem(PRINT_TRAY_KEY, JSON.stringify(tray));
            } catch (err) {
                console.error("Tray save error:", err);
            }
        }

        // CSSフィルター文字列生成 (病巣強調 IEE ＋ 階調 ＋ 反転)
        function getFilterString(item) {
            if (!item) return 'none';
            let filterParts = [];
            if (item.contrast && item.contrast !== 100) filterParts.push(`contrast(${item.contrast}%)`);
            if (item.brightness && item.brightness !== 100) filterParts.push(`brightness(${item.brightness}%)`);
            if (item.invert) filterParts.push('invert(100%)');

            if (item.ieeMode && item.ieeMode !== 'normal') {
                filterParts.push(`url(#iee-${item.ieeMode})`);
                if (item.ieeMode === 'lci') filterParts.push('saturate(135%)');
                else if (item.ieeMode === 'txi') filterParts.push('saturate(120%)');
                else if (item.ieeMode === 'pale') filterParts.push('saturate(115%)');
                else if (item.ieeMode === 'nbi') filterParts.push('saturate(125%)');
            }
            return filterParts.length > 0 ? filterParts.join(' ') : 'none';
        }

        function getIeeLabel(mode, invert) {
            if (invert) return '🌓 反転';
            const map = {
                'lci': '🔴 LCI',
                'nbi': '🟢 NBI',
                'txi': '🔆 TXI',
                'pale': '⚪ 褪色',
                'ihb': '📊 IHb',
                'texture': '🏔️ 構造',
                'indigo': '🔵 色素'
            };
            return map[mode] || '';
        }

        // 2×4 グリッドの描画
        function renderGrid() {
            sheetGrid.innerHTML = '';
            closeAllPopovers();

            for (let i = 0; i < 8; i++) {
                const cell = document.createElement('div');
                cell.className = 'grid-cell';
                cell.dataset.slotIndex = i;

                const item = slots[i];

                if (item) {
                    // 画像ありスロット
                    const viewport = document.createElement('div');
                    viewport.className = 'cell-viewport';
                    viewport.dataset.slotIndex = i;

                    const img = document.createElement('img');
                    img.alt = item.file;
                    img.draggable = false;
                    img.src = `dcm_api.php?action=image&folder=${encodeURIComponent(item.folder)}&file=${encodeURIComponent(item.file)}`;

                    // 初期トランスフォーム & フィルター適用
                    updateImageTransform(img, item.panX, item.panY, item.zoom);
                    img.style.filter = getFilterString(item);

                    // 枠番号バッジ
                    const badge = document.createElement('div');
                    badge.className = 'cell-badge no-print';
                    badge.textContent = `#${i + 1}`;

                    // 病巣強調バッジ (LCI / NBI / 色素 等)
                    const ieeTag = getIeeLabel(item.ieeMode, item.invert);
                    if (ieeTag) {
                        const ieeBadge = document.createElement('div');
                        ieeBadge.className = 'cell-iee-badge';
                        ieeBadge.textContent = ieeTag;
                        viewport.appendChild(ieeBadge);
                    }

                    // フローティングツールバー (拡大・縮小・リセット・入替・複製・強調切替・削除)
                    const tools = document.createElement('div');
                    tools.className = 'cell-tools no-print';

                    tools.innerHTML = `
                        <button type="button" class="cell-tool-btn" data-act="prev" title="左/上の枠と入れ替え">◀</button>
                        <button type="button" class="cell-tool-btn" data-act="next" title="右/下の枠と入れ替え">▶</button>
                        <button type="button" class="cell-tool-btn btn-highlight" data-act="duplicate" title="この枠を空き枠に複製 (同一画像の一部拡大・強調比較用)">📋 複製</button>
                        <button type="button" class="cell-tool-btn btn-highlight" data-act="ieeMenu" title="病巣強調表示切替 (LCI, NBI, TXI, 色素散布 等)">🔬 強調</button>
                        <button type="button" class="cell-tool-btn" data-act="zoomIn" title="ズーム拡大 [+]">＋</button>
                        <button type="button" class="cell-tool-btn" data-act="zoomOut" title="ズーム縮小 [-]">－</button>
                        <button type="button" class="cell-tool-btn" data-act="reset" title="トリミング位置・ズーム・強調初期化">↺</button>
                        <button type="button" class="cell-tool-btn btn-del" data-act="delete" title="この枠から画像を削除">✕</button>
                    `;

                    tools.addEventListener('click', e => {
                        const btn = e.target.closest('.cell-tool-btn');
                        if (!btn) return;
                        e.stopPropagation();
                        handleCellToolAction(i, btn.dataset.act, btn);
                    });

                    viewport.appendChild(img);
                    viewport.appendChild(badge);
                    viewport.appendChild(tools);

                    // 直感ドラッグ＆ズーム（パン＆ズーム・トリミング操作）バインド
                    bindPanZoomEvents(viewport, img, item);

                    // キャプションバー (検査名・撮影日時・所見編集)
                    const captionBar = document.createElement('div');
                    captionBar.className = 'cell-caption';

                    const captionText = document.createElement('div');
                    captionText.className = 'caption-text';
                    captionText.contentEditable = 'true';
                    captionText.spellcheck = false;
                    captionText.textContent = item.caption;
                    captionText.title = "クリックして所見やコメントを直接編集できます";

                    captionText.addEventListener('blur', () => {
                        item.caption = captionText.textContent;
                        saveTrayToStorage();
                    });

                    captionBar.appendChild(captionText);

                    cell.appendChild(viewport);
                    cell.appendChild(captionBar);

                } else {
                    // 空きスロット
                    const empty = document.createElement('div');
                    empty.className = 'cell-empty';
                    empty.innerHTML = `
                        <div class="cell-badge no-print" style="position:static; align-self:flex-start; margin-left:4px;">#${i + 1}</div>
                        <div class="cell-empty-icon">➕</div>
                        <div class="cell-empty-text">画像を追加</div>
                    `;

                    empty.addEventListener('click', () => {
                        activeTargetSlotIndex = i;
                        openPickerDrawer();
                    });

                    cell.appendChild(empty);
                }

                sheetGrid.appendChild(cell);
            }
        }

        // 直感ドラッグ＆ズーム (パン＆ズーム・トリミング)
        function bindPanZoomEvents(viewport, img, item) {
            let isDragging = false;
            let startX = 0;
            let startY = 0;
            let initPanX = 0;
            let initPanY = 0;

            viewport.addEventListener('mousedown', e => {
                if (e.button !== 0) return; // 左ドラッグのみ
                if (e.target.closest('.cell-tools') || e.target.closest('.iee-popover')) return;
                e.preventDefault();
                isDragging = true;
                viewport.classList.add('grabbing');
                startX = e.clientX;
                startY = e.clientY;
                initPanX = item.panX || 0;
                initPanY = item.panY || 0;
            });

            window.addEventListener('mousemove', e => {
                if (!isDragging) return;
                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                item.panX = Math.round(initPanX + dx);
                item.panY = Math.round(initPanY + dy);
                updateImageTransform(img, item.panX, item.panY, item.zoom);
            });

            window.addEventListener('mouseup', () => {
                if (!isDragging) return;
                isDragging = false;
                viewport.classList.remove('grabbing');
                saveTrayToStorage();
            });

            // ホイールで拡大縮小 (ズーム)
            viewport.addEventListener('wheel', e => {
                e.preventDefault();
                const delta = e.deltaY < 0 ? 0.1 : -0.1;
                item.zoom = Math.max(1.0, Math.min(5.0, Math.round((item.zoom + delta) * 10) / 10));
                updateImageTransform(img, item.panX, item.panY, item.zoom);
                saveTrayToStorage();
            }, { passive: false });

            // ダブルクリックでリセット
            viewport.addEventListener('dblclick', e => {
                if (e.target.closest('.cell-tools') || e.target.closest('.iee-popover')) return;
                item.panX = 0;
                item.panY = 0;
                item.zoom = 1.0;
                updateImageTransform(img, 0, 0, 1.0);
                saveTrayToStorage();
                showToast(`枠 #${parseInt(viewport.dataset.slotIndex, 10) + 1} の配置をリセットしました`);
            });
        }

        function updateImageTransform(img, px, py, z) {
            img.style.transform = `translate(${px}px, ${py}px) scale(${z})`;
        }

        // 個別枠のボタン操作
        function handleCellToolAction(index, action, anchorBtn) {
            const item = slots[index];
            if (!item) return;

            if (action === 'delete') {
                slots[index] = null;
                saveTrayToStorage();
                renderGrid();
                showToast(`🗑️ 枠 #${index + 1} を削除しました`);
            } else if (action === 'reset') {
                item.panX = 0;
                item.panY = 0;
                item.zoom = 1.0;
                item.ieeMode = 'normal';
                item.invert = false;
                item.contrast = 100;
                item.brightness = 100;
                saveTrayToStorage();
                renderGrid();
                showToast(`🔄 枠 #${index + 1} を初期化しました`);
            } else if (action === 'zoomIn') {
                item.zoom = Math.min(5.0, Math.round((item.zoom + 0.15) * 100) / 100);
                saveTrayToStorage();
                renderGrid();
            } else if (action === 'zoomOut') {
                item.zoom = Math.max(1.0, Math.round((item.zoom - 0.15) * 100) / 100);
                saveTrayToStorage();
                renderGrid();
            } else if (action === 'prev') {
                if (index > 0) {
                    const temp = slots[index - 1];
                    slots[index - 1] = slots[index];
                    slots[index] = temp;
                    saveTrayToStorage();
                    renderGrid();
                }
            } else if (action === 'next') {
                if (index < 7) {
                    const temp = slots[index + 1];
                    slots[index + 1] = slots[index];
                    slots[index] = temp;
                    saveTrayToStorage();
                    renderGrid();
                }
            } else if (action === 'duplicate') {
                duplicateSlot(index);
            } else if (action === 'ieeMenu') {
                showIeeMenu(index, anchorBtn);
            }
        }

        // 同一枠の複製（一部拡大・強調比較用）
        function duplicateSlot(index) {
            const item = slots[index];
            if (!item) return;

            const emptyIdx = slots.findIndex(s => s === null);
            if (emptyIdx === -1) {
                alert("全8枠がすべて埋まっています。\n不要な枠を削除して空きを作ってから複製してください。");
                return;
            }

            // 同一画像を別枠に複製
            const cleanDate = (item.examDate || '').split(' ')[0] || '';
            const cap = item.caption.includes('[拡大・強調]') ? item.caption : (item.caption + ' [拡大・強調]');

            slots[emptyIdx] = {
                id: `${item.folder}_${item.file}_${Date.now()}`,
                folder: item.folder,
                file: item.file,
                examName: item.examName,
                examDate: item.examDate,
                caption: cap,
                panX: item.panX || 0,
                panY: item.panY || 0,
                zoom: Math.max(item.zoom || 1.0, 1.4), // 比較しやすいよう初期拡大率をセット
                ieeMode: item.ieeMode || 'normal',
                contrast: item.contrast || 100,
                brightness: item.brightness || 100,
                invert: !!item.invert
            };

            saveTrayToStorage();
            renderGrid();
            showToast(`枠 #${index + 1} を 枠 #${emptyIdx + 1} に複製しました（病変部を拡大＆強調切替できます）`);
        }

        // 病巣強調モード選択メニュー (ポップオーバー)
        function showIeeMenu(slotIndex, anchorBtn) {
            closeAllPopovers();

            const item = slots[slotIndex];
            if (!item) return;

            const popover = document.createElement('div');
            popover.className = 'iee-popover no-print';

            const modes = [
                { id: 'normal',  label: '🌈 通常 (白色光)',      tag: '【白色光】' },
                { id: 'lci',     label: '🔴 発赤強調 (LCI)',     tag: '【LCI発赤強調】' },
                { id: 'nbi',     label: '🟢 微細血管 (NBI)',     tag: '【NBI血管強調】' },
                { id: 'txi',     label: '🔆 自然強調 (TXI)',     tag: '【TXI自然強調】' },
                { id: 'pale',    label: '⚪ 褪色強調 (Pale/SSA)', tag: '【褪色強調】' },
                { id: 'ihb',     label: '📊 血流マップ (IHb)',   tag: '【血流マップ】' },
                { id: 'texture', label: '🏔️ 構造強調 (輪郭)',    tag: '【構造強調】' },
                { id: 'indigo',  label: '🔵 色素散布 (インジゴ)', tag: '【色素散布】' },
                { id: 'invert',  label: '🌓 白黒反転 (XP/透視)', tag: '【白黒反転】' }
            ];

            modes.forEach(m => {
                const btn = document.createElement('button');
                btn.type = 'button';
                const isActive = (m.id === 'invert') ? item.invert : (item.ieeMode === m.id && !item.invert);
                btn.className = 'iee-popover-item' + (isActive ? ' active' : '');
                btn.textContent = m.label;

                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    if (m.id === 'invert') {
                        item.invert = !item.invert;
                    } else {
                        item.ieeMode = m.id;
                        item.invert = false;
                    }

                    // キャプションの強調タグ自動反映
                    const cleanDate = (item.examDate || '').split(' ')[0] || '';
                    const base = `[${item.examName || '検査'}] ${cleanDate} (${item.file})`;
                    const zoomStr = (item.zoom >= 1.25) ? ' [拡大]' : '';
                    item.caption = `${base} ${m.tag}${zoomStr}`;

                    saveTrayToStorage();
                    renderGrid();
                    closeAllPopovers();
                    showToast(`枠 #${slotIndex + 1} を ${m.label} に設定しました`);
                });

                popover.appendChild(btn);
            });

            anchorBtn.closest('.grid-cell').appendChild(popover);

            setTimeout(() => {
                window.addEventListener('click', closeAllPopovers, { once: true });
            }, 20);
        }

        function closeAllPopovers() {
            document.querySelectorAll('.iee-popover').forEach(p => p.remove());
        }

        // ----------------------------------------------------------------------
        // 検査横断画像ピッカー・ドロワー制御
        // ----------------------------------------------------------------------
        function openPickerDrawer() {
            pickerDrawer.classList.add('open');
            if (drawerBody.children.length === 0) {
                renderExamListInDrawer();
            }
        }

        function closePickerDrawer() {
            pickerDrawer.classList.remove('open');
            activeTargetSlotIndex = null;
        }

        btnToggleDrawer.addEventListener('click', () => {
            if (pickerDrawer.classList.contains('open')) {
                closePickerDrawer();
            } else {
                openPickerDrawer();
            }
        });
        btnDrawerClose.addEventListener('click', closePickerDrawer);

        // ドロワー内に検査リストを展開
        function renderExamListInDrawer() {
            drawerBody.innerHTML = '';

            if (!EXAM_LIST || EXAM_LIST.length === 0) {
                drawerBody.innerHTML = `<div style="color:#94a3b8; text-align:center; padding:30px;">利用可能な過去検査データがありません</div>`;
                return;
            }

            EXAM_LIST.forEach((exam, eIdx) => {
                const card = document.createElement('div');
                card.className = 'exam-item-card';

                const header = document.createElement('div');
                header.className = 'exam-item-header';
                header.innerHTML = `
                    <div class="exam-item-title">
                        <span class="exam-item-badge" style="background:${exam.badge_color};">${exam.cat_label}</span>
                        <span>${escapeHtml(exam.kensamei)}</span>
                    </div>
                    <div class="exam-item-date">${escapeHtml(exam.date)} ▾</div>
                `;

                const grid = document.createElement('div');
                grid.className = 'exam-thumb-grid';
                grid.style.display = 'none'; // クリックで展開

                let loaded = false;

                header.addEventListener('click', async () => {
                    const isOpening = (grid.style.display === 'none');
                    grid.style.display = isOpening ? 'grid' : 'none';

                    if (isOpening && !loaded) {
                        loaded = true;
                        grid.innerHTML = `<div style="grid-column:1/-1; color:#94a3b8; text-align:center; padding:10px;">画像一覧を取得中...</div>`;
                        try {
                            const res = await fetch(`dcm_api.php?action=list&folder=${encodeURIComponent(exam.folder)}`);
                            const data = await res.json();
                            if (data.status === 'success' && data.files && data.files.length > 0) {
                                grid.innerHTML = '';
                                data.files.forEach((f, fIdx) => {
                                    const thumbCard = document.createElement('div');
                                    thumbCard.className = 'picker-thumb-card';
                                    thumbCard.title = `${f.filename} (クリックで追加)`;

                                    const img = document.createElement('img');
                                    img.loading = 'lazy';
                                    img.src = `dcm_api.php?action=image&folder=${encodeURIComponent(exam.folder)}&file=${encodeURIComponent(f.filename)}&thumb=1`;

                                    const badge = document.createElement('span');
                                    badge.className = 'picker-thumb-badge';
                                    badge.textContent = `#${fIdx + 1}`;

                                    thumbCard.appendChild(img);
                                    thumbCard.appendChild(badge);

                                    thumbCard.addEventListener('click', () => {
                                        pickImageToSlot(exam, f.filename);
                                    });

                                    grid.appendChild(thumbCard);
                                });
                            } else {
                                grid.innerHTML = `<div style="grid-column:1/-1; color:#ef4444; text-align:center; padding:10px;">画像が見つかりませんでした</div>`;
                            }
                        } catch (err) {
                            grid.innerHTML = `<div style="grid-column:1/-1; color:#ef4444; text-align:center; padding:10px;">通信エラー: ${escapeHtml(err.message)}</div>`;
                        }
                    }
                });

                card.appendChild(header);
                card.appendChild(grid);
                drawerBody.appendChild(card);
            });
        }

        // ピッカーからスロットへの画像配置
        function pickImageToSlot(exam, filename) {
            let targetIdx = activeTargetSlotIndex;

            // ターゲットが指定されていない場合、最初の空きスロットを探す
            if (targetIdx === null || targetIdx < 0 || targetIdx >= 8) {
                targetIdx = slots.findIndex(s => s === null);
            }

            if (targetIdx === -1) {
                alert("全8枠がすべて埋まっています。\n空き枠を作るか、削除してから追加してください。");
                return;
            }

            const cleanDate = (exam.date || '').split(' ')[0] || '';
            const caption = `[${exam.kensamei}] ${cleanDate} (${filename})`;

            slots[targetIdx] = {
                id: `${exam.folder}_${filename}_${Date.now()}`,
                folder: exam.folder,
                file: filename,
                examName: exam.kensamei,
                examDate: exam.date,
                caption: caption,
                panX: 0,
                panY: 0,
                zoom: 1.0,
                ieeMode: 'normal',
                contrast: 100,
                brightness: 100,
                invert: false
            };

            saveTrayToStorage();
            renderGrid();
            showToast(`枠 #${targetIdx + 1} に ${filename} を追加しました`);

            activeTargetSlotIndex = null;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[m]);
        }

        // ----------------------------------------------------------------------
        // ツールバー操作
        // ----------------------------------------------------------------------
        // 印刷実行 (Ctrl+P / 印刷ボタン)
        btnPrintRun.addEventListener('click', () => {
            window.print();
        });

        // 全枠リセット
        btnResetAllSlots.addEventListener('click', () => {
            if (!confirm("すべての画像のトリミング位置、ズーム倍率、強調設定を初期位置にリセットしますか？")) return;
            slots.forEach(item => {
                if (item) {
                    item.panX = 0;
                    item.panY = 0;
                    item.zoom = 1.0;
                    item.ieeMode = 'normal';
                    item.invert = false;
                    item.contrast = 100;
                    item.brightness = 100;
                }
            });
            saveTrayToStorage();
            renderGrid();
            showToast("全枠の配置・強調設定を初期化しました");
        });

        // トレイ全消去
        btnClearTray.addEventListener('click', () => {
            if (!confirm("印刷トレイ内のすべての画像をクリアしますか？")) return;
            slots = Array(8).fill(null);
            saveTrayToStorage();
            renderGrid();
            showToast("印刷トレイを全消去しました");
        });

        // プレビュー表示倍率スライダー
        zoomSheetRange.addEventListener('input', e => {
            const val = parseFloat(e.target.value);
            document.documentElement.style.setProperty('--sheet-zoom', val);
            zoomSheetVal.textContent = `${Math.round(val * 100)}%`;
        });

        // 閉じる
        btnClosePage.addEventListener('click', () => {
            window.close();
        });

        // ショートカットキー (印刷 Ctrl+P)
        window.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P')) {
                e.preventDefault();
                window.print();
            } else if (e.key === 'Escape') {
                if (pickerDrawer.classList.contains('open')) {
                    closePickerDrawer();
                }
                closeAllPopovers();
            }
        });

        // 別タブでの変更監視
        window.addEventListener('storage', e => {
            if (e.key === PRINT_TRAY_KEY) {
                loadTrayFromStorage();
                renderGrid();
            }
        });

        // 初期化
        loadTrayFromStorage();
        renderGrid();
    </script>
</body>
</html>
