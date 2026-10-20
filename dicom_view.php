<?php
// ==============================================================================
// RS-Base 高性能ダイレクトDICOMビューワー (dicom_view.php)
// 横長液晶ディスプレイ最適化・PACS標準マウス操作・マルチモニタ対応
// ==============================================================================

$folder    = $_GET['folder'] ?? '';
$pat_id    = $_GET['id'] ?? '';
$pat_name  = $_GET['name'] ?? '';
$exam_name = $_GET['kensa'] ?? '';
$exam_date = $_GET['date'] ?? '';
$display_date = preg_replace('/^(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}\s+\d{1,2}:\d{2})(?::\d{2})?.*$/', '$1', trim($exam_date));
$sindan    = $_GET['sindan'] ?? '';
$mode      = $_GET['mode'] ?? 'standalone'; // 'modal' or 'standalone'
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DICOM Viewer - <?= htmlspecialchars($pat_name ? "{$pat_name} ({$pat_id})" : "RS-Base", ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --bg-base: #07090e;
            --bg-panel: #0f172a;
            --bg-panel-hover: #1e293b;
            --border-color: #334155;
            --accent-blue: #38bdf8;
            --accent-glow: rgba(56, 189, 248, 0.4);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            user-select: none;
            -webkit-user-select: none;
        }

        html, body {
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            background-color: var(--bg-base);
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            flex-direction: column;
        }

        /* --------------------------------------------------------------------------
           上部ヘッダーツールバー
        -------------------------------------------------------------------------- */
        .dcm-header {
            height: 48px;
            background: linear-gradient(180deg, #111827 0%, #0b0f19 100%);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 12px;
            z-index: 100;
            flex-shrink: 0;
            gap: 12px;
        }

        .dcm-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .badge-id {
            background: #1e3a8a;
            color: #bfdbfe;
            padding: 2px 7px;
            border-radius: 4px;
            font-weight: 700;
            font-family: monospace;
            font-size: 0.85rem;
            border: 1px solid #3b82f6;
        }

        .patient-name {
            font-weight: 700;
            color: #ffffff;
            font-size: 0.95rem;
        }

        .exam-badge {
            background: #065f46;
            color: #a7f3d0;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid #10b981;
        }

        .exam-date, .exam-sindan {
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        /* コマ送り・ナビゲーション */
        .dcm-slice-nav {
            display: flex;
            align-items: center;
            background: #1e293b;
            border: 1px solid #475569;
            border-radius: 6px;
            overflow: hidden;
            height: 32px;
        }

        .nav-btn {
            background: transparent;
            border: none;
            color: var(--text-main);
            padding: 0 10px;
            height: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
        }

        .nav-btn:hover:not(:disabled) {
            background: #334155;
            color: var(--accent-blue);
        }

        .nav-btn:disabled {
            color: var(--text-dim);
            cursor: not-allowed;
        }

        .slice-label {
            padding: 0 10px;
            font-family: monospace;
            font-size: 0.85rem;
            font-weight: 600;
            color: #e2e8f0;
            white-space: nowrap;
        }

        /* 画面分割レイアウト切替ボタングループ */
        .layout-group {
            display: flex;
            align-items: center;
            background: #111827;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
            padding: 2px;
            gap: 2px;
        }

        .layout-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 4px 10px;
            font-size: 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
            font-weight: 500;
        }

        .layout-btn:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.05);
        }

        .layout-btn.active {
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        }

        /* マウスモード切替ボタングループ */
        .tool-group {
            display: flex;
            align-items: center;
            background: #111827;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
            padding: 2px;
            gap: 2px;
        }

        .mode-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 4px 10px;
            font-size: 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
            font-weight: 500;
        }

        .mode-btn:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.05);
        }

        .mode-btn.active {
            background: #0284c7;
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        }

        /* ツールアクションボタン */
        .dcm-actions {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-shrink: 0;
        }

        .action-btn {
            background: #1e293b;
            border: 1px solid #475569;
            color: #e2e8f0;
            padding: 5px 10px;
            font-size: 0.8rem;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
            height: 32px;
        }

        .action-btn:hover {
            background: #334155;
            color: #ffffff;
            border-color: #64748b;
        }

        .action-btn.active-toggle {
            background: #b45309;
            border-color: #f59e0b;
            color: #fef3c7;
        }

        .action-btn.btn-close {
            background: #991b1b;
            border-color: #dc2626;
            color: #fef2f2;
            font-weight: 700;
        }
        .action-btn.btn-close:hover {
            background: #b91c1c;
        }

        /* --------------------------------------------------------------------------
           メインワークスペース (ステージ ＋ 右側縦型サムネイル)
        -------------------------------------------------------------------------- */
        .dcm-workspace {
            display: flex;
            flex: 1;
            height: calc(100vh - 48px);
            position: relative;
            overflow: hidden;
        }

        /* メイン画像ステージ (CSS Grid による分割表示) */
        .dcm-stage {
            flex: 1;
            height: 100%;
            position: relative;
            background: #030712;
            overflow: hidden;
            cursor: crosshair;
            display: grid;
            gap: 2px;
        }

        /* 1面: 1x1 */
        .dcm-stage.layout-1 {
            grid-template-columns: 1fr;
            grid-template-rows: 1fr;
        }
        .dcm-stage.layout-1 .dcm-viewport[data-slot="1"],
        .dcm-stage.layout-1 .dcm-viewport[data-slot="2"],
        .dcm-stage.layout-1 .dcm-viewport[data-slot="3"] {
            display: none;
        }

        /* 2面: 1行2列 (1x2 左右並び) */
        .dcm-stage.layout-2 {
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr;
        }
        .dcm-stage.layout-2 .dcm-viewport[data-slot="2"],
        .dcm-stage.layout-2 .dcm-viewport[data-slot="3"] {
            display: none;
        }

        /* 4面: 2行2列 (2x2 田の字並び) */
        .dcm-stage.layout-4 {
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
        }

        .dcm-viewport {
            position: relative;
            overflow: hidden;
            background: radial-gradient(circle at center, #0f172a 0%, #05070c 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            width: 100%;
            height: 100%;
        }

        .dcm-viewport .dcm-img-wrapper {
            position: absolute;
            left: 0;
            top: 0;
            transform-origin: 0 0;
            will-change: transform;
            pointer-events: none;
        }

        .dcm-viewport .dcm-slot-img {
            display: block;
            max-width: none;
            pointer-events: none;
            transition: filter 0.05s ease-out;
        }

        .slot-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(56, 189, 248, 0.4);
            color: #38bdf8;
            font-family: monospace;
            font-weight: 700;
            font-size: 0.8rem;
            padding: 2px 8px;
            border-radius: 4px;
            z-index: 10;
            pointer-events: none;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.5);
        }

        .slot-empty-msg {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #64748b;
            font-size: 0.9rem;
            display: none;
            pointer-events: none;
            font-family: sans-serif;
        }
        .dcm-viewport.is-empty .slot-empty-msg {
            display: block;
        }
        .dcm-viewport.is-empty .slot-badge {
            display: none;
        }
        .dcm-viewport.is-empty .dcm-slot-img {
            display: none;
        }

        /* 浮遊ナビゲーション大型矢印 (左右) */
        .float-nav-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 44px;
            height: 72px;
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s;
            z-index: 40;
            backdrop-filter: blur(4px);
        }

        .float-nav-btn:hover:not(:disabled) {
            background: rgba(14, 165, 233, 0.7);
            color: #ffffff;
            border-color: #38bdf8;
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.5);
        }

        .float-nav-btn:disabled {
            opacity: 0.15;
            cursor: not-allowed;
        }

        .float-prev { left: 16px; }
        .float-next { right: 16px; }

        /* 左下HUD (パラメータ表示) */
        .dcm-hud {
            position: absolute;
            bottom: 12px;
            left: 12px;
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(6px);
            padding: 6px 12px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.75rem;
            color: var(--text-muted);
            pointer-events: none;
            z-index: 30;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .dcm-hud span strong {
            color: var(--accent-blue);
        }

        /* 操作ヘルプガイド (右下フローティング) */
        .mouse-guide {
            position: absolute;
            bottom: 12px;
            right: 12px;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(4px);
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            color: var(--text-dim);
            pointer-events: none;
            z-index: 30;
            line-height: 1.4;
        }

        /* ローディングオーバーレイ */
        .dcm-loader {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(7, 9, 14, 0.65);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 12px;
            z-index: 50;
        }

        .dcm-spinner {
            width: 44px;
            height: 44px;
            border: 4px solid rgba(56, 189, 248, 0.2);
            border-top: 4px solid var(--accent-blue);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* --------------------------------------------------------------------------
           右側・縦型サムネイルパネル (横長画面最適化)
        -------------------------------------------------------------------------- */
        .dcm-sidebar {
            width: 150px;
            background: var(--bg-panel);
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            z-index: 45;
            flex-shrink: 0;
            transition: width 0.2s ease;
            height: 100%;
        }

        .dcm-sidebar.collapsed {
            width: 0;
            overflow: hidden;
            border-left: none;
        }

        .sidebar-header {
            height: 36px;
            padding: 0 10px;
            background: #111827;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            flex-shrink: 0;
        }

        .sidebar-header .toggle-btn {
            background: transparent;
            border: none;
            color: var(--text-dim);
            cursor: pointer;
            font-size: 0.9rem;
            padding: 2px 4px;
            border-radius: 3px;
        }
        .sidebar-header .toggle-btn:hover {
            color: var(--text-main);
            background: #1e293b;
        }

        .filmstrip-list {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        /* スクロールバーのカスタマイズ */
        .filmstrip-list::-webkit-scrollbar {
            width: 6px;
        }
        .filmstrip-list::-webkit-scrollbar-track {
            background: #0b0f19;
        }
        .filmstrip-list::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 3px;
        }
        .filmstrip-list::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }

        /* 📸 サムネイルカード: flex-shrink:0 で枚数が多くても絶対に潰れない */
        .thumb-card {
            width: 100%;
            height: 125px;
            min-height: 125px;
            flex-shrink: 0;
            background: #020617;
            border: 2px solid #334155;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3px;
            box-sizing: border-box;
        }

        .thumb-card:hover {
            border-color: #64748b;
            transform: translateY(-1px);
        }

        .thumb-card.active {
            border-color: var(--accent-blue);
            box-shadow: 0 0 12px var(--accent-glow);
            background: #0b1329;
        }

        /* 🖼️ 画像縦横比(アスペクト比)を完全維持 & はみ出しトリミング防止 */
        .thumb-card img {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
            border-radius: 3px;
        }

        .thumb-badge {
            position: absolute;
            bottom: 3px;
            right: 4px;
            background: rgba(0, 0, 0, 0.75);
            color: #f1f5f9;
            font-family: monospace;
            font-size: 0.68rem;
            padding: 1px 5px;
            border-radius: 3px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            font-weight: 600;
        }

        /* サイドバートグル復帰ボタン (ステージ右端) */
        .sidebar-reopen-btn {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 48px;
            background: #1e293b;
            border: 1px solid var(--border-color);
            border-right: none;
            color: var(--text-muted);
            border-radius: 4px 0 0 4px;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            z-index: 44;
        }
        .sidebar-reopen-btn:hover {
            background: #334155;
            color: #ffffff;
        }

        /* --------------------------------------------------------------------------
           画面下部・内視鏡病変強調ドック (IEE: Image-Enhanced Endoscopy)
        -------------------------------------------------------------------------- */
        .iee-dock {
            position: absolute;
            bottom: 12px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 5px;
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(56, 189, 248, 0.35);
            backdrop-filter: blur(8px);
            padding: 4px 10px;
            border-radius: 30px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.65), 0 0 12px rgba(56, 189, 248, 0.2);
            z-index: 45;
        }

        .iee-label {
            font-size: 0.76rem;
            color: #7dd3fc;
            font-weight: 700;
            padding-left: 4px;
            padding-right: 4px;
            display: flex;
            align-items: center;
            gap: 3px;
            white-space: nowrap;
        }

        .iee-btn {
            background: #1e293b;
            border: 1px solid #334155;
            color: #cbd5e1;
            padding: 5px 11px;
            font-size: 0.78rem;
            border-radius: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .iee-btn:hover {
            color: #ffffff;
            background: #334155;
            border-color: #64748b;
            transform: translateY(-1px);
        }

        .iee-btn.active {
            background: #0284c7;
            border-color: #38bdf8;
            color: #ffffff;
            box-shadow: 0 0 12px rgba(56, 189, 248, 0.5);
            font-weight: 700;
        }

        @media (max-width: 960px) {
            .mouse-guide {
                display: none;
            }
        }

        /* トースト通知 (ドック上部に浮遊表示) */
        #dcmToast {
            position: fixed;
            bottom: 60px;
            left: 50%;
            transform: translateX(-50%) translateY(50px);
            background: rgba(15, 23, 42, 0.95);
            color: #ffffff;
            border: 1px solid var(--accent-blue);
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 0.85rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
            opacity: 0;
            transition: all 0.25s ease;
            z-index: 999;
            pointer-events: none;
        }
        #dcmToast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
    </style>
</head>
<body>

    <!-- ----------------------------------------------------------------------
         上部ヘッダー
    ----------------------------------------------------------------------- -->
    <header class="dcm-header">
        <div class="dcm-meta">
            <span class="badge-id">ID: <?= htmlspecialchars($pat_id ?: '----', ENT_QUOTES, 'UTF-8') ?></span>
            <span class="patient-name"><?= htmlspecialchars($pat_name ?: '患者未指定', ENT_QUOTES, 'UTF-8') ?> 様</span>
            <?php if ($exam_name): ?>
                <span class="exam-badge"><?= htmlspecialchars($exam_name, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <span class="exam-date"><?= htmlspecialchars($display_date, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($sindan): ?>
                <span class="exam-sindan" title="<?= htmlspecialchars($sindan, ENT_QUOTES, 'UTF-8') ?>">
                    [<?= htmlspecialchars(mb_strimwidth($sindan, 0, 20, '...'), ENT_QUOTES, 'UTF-8') ?>]
                </span>
            <?php endif; ?>
        </div>

        <!-- スライス移動ナビ -->
        <div class="dcm-slice-nav">
            <button type="button" class="nav-btn" id="navPrevBtn" title="前コマ [←キー / ホイール上]">◀</button>
            <span class="slice-label" id="sliceNavLabel">0 / 0</span>
            <button type="button" class="nav-btn" id="navNextBtn" title="次コマ [→キー / ホイール下]">▶</button>
        </div>

        <!-- 画面分割レイアウト切替 -->
        <div class="layout-group" id="layoutGroup">
            <button type="button" class="layout-btn active" data-layout="1" title="1画面表示 [1キー]">
                1面
            </button>
            <button type="button" class="layout-btn" data-layout="2" title="2画面横並び表示 [2キー]">
                2面
            </button>
            <button type="button" class="layout-btn" data-layout="4" title="4画面田の字表示 [4キー]">
                4面
            </button>
        </div>

        <!-- マウス操作モード切替 (左ドラッグの動作) -->
        <div class="tool-group">
            <button type="button" class="mode-btn active" id="modeWwWl" title="左ドラッグ: 輝度(WL)・コントラスト(WW)調整">
                🌓 階調
            </button>
            <button type="button" class="mode-btn" id="modePan" title="左ドラッグ: 画像の平行移動 (パン)">
                ✋ 移動
            </button>
            <button type="button" class="mode-btn" id="modeZoom" title="左ドラッグ: 拡大・縮小 (ズーム)">
                🔍 ズーム
            </button>
        </div>

        <!-- アクションツール -->
        <div class="dcm-actions">
            <button type="button" class="action-btn" id="btnFit" title="画面に合わせる [Fキー / ダブルクリック]">
                ⏹️ フィット
            </button>
            <button type="button" class="action-btn" id="btnOneToOne" title="原寸大 (1:1) で表示">
                1:1
            </button>
            <button type="button" class="action-btn" id="btnInvert" title="白黒反転 [Iキー]">
                🌓 反転
            </button>
            <button type="button" class="action-btn" id="btnReset" title="位置・ズーム・階調をリセット [Rキー]">
                🔄 リセット
            </button>
            <button type="button" class="action-btn" id="btnDownloadZip" title="JPEG一括ZIPダウンロード">
                📦 ZIP
            </button>
            <button type="button" class="action-btn" id="btnDirectLaunch" title="外部DICOM専用ビューワを直接起動">
                🚀 外部
            </button>
            <?php if ($mode === 'modal'): ?>
                <button type="button" class="action-btn" id="btnPopout" title="新しいウィンドウで大画面表示">
                    🪟 別窓
                </button>
                <button type="button" class="action-btn btn-close" id="btnCloseModal" title="閉じる [Escキー]">
                    ✕
                </button>
            <?php endif; ?>
        </div>
    </header>

    <!-- ----------------------------------------------------------------------
         メインワークスペース
    ----------------------------------------------------------------------- -->
    <main class="dcm-workspace">
        <!-- 画像ステージ -->
        <div class="dcm-stage layout-1" id="dcmImageStage">
            <!-- スロット 0 (主画面 / 1面時) -->
            <div class="dcm-viewport" data-slot="0">
                <div class="dcm-img-wrapper" id="dcmImgWrapper">
                    <img class="dcm-slot-img" id="dcmMainImage" src="" alt="DICOM Main" draggable="false">
                </div>
                <div class="slot-badge" id="badgeSlot0">#1</div>
                <div class="slot-empty-msg">(画像なし)</div>
            </div>

            <!-- スロット 1 (2面・4面時) -->
            <div class="dcm-viewport" data-slot="1">
                <div class="dcm-img-wrapper">
                    <img class="dcm-slot-img" src="" alt="" draggable="false">
                </div>
                <div class="slot-badge" id="badgeSlot1">#2</div>
                <div class="slot-empty-msg">(画像なし)</div>
            </div>

            <!-- スロット 2 (4面時) -->
            <div class="dcm-viewport" data-slot="2">
                <div class="dcm-img-wrapper">
                    <img class="dcm-slot-img" src="" alt="" draggable="false">
                </div>
                <div class="slot-badge" id="badgeSlot2">#3</div>
                <div class="slot-empty-msg">(画像なし)</div>
            </div>

            <!-- スロット 3 (4面時) -->
            <div class="dcm-viewport" data-slot="3">
                <div class="dcm-img-wrapper">
                    <img class="dcm-slot-img" src="" alt="" draggable="false">
                </div>
                <div class="slot-badge" id="badgeSlot3">#4</div>
                <div class="slot-empty-msg">(画像なし)</div>
            </div>

            <!-- 左右大型フローティング矢印 -->
            <button type="button" class="float-nav-btn float-prev" id="floatPrevBtn" title="前コマ [←]">◀</button>
            <button type="button" class="float-nav-btn float-next" id="floatNextBtn" title="次コマ [→]">▶</button>

            <!-- 左下HUD -->
            <div class="dcm-hud" id="dcmHud">
                <span>ファイル: <strong id="hudFilename">--</strong></span>
                <span>解像度: <strong id="hudResolution">--</strong></span>
                <span>倍率: <strong id="hudZoom">100%</strong></span>
                <span>階調: <strong id="hudWwWl">C:100% B:100%</strong></span>
            </div>

            <!-- 右下マウス操作ガイド -->
            <div class="mouse-guide">
                🖱️ <strong>標準マウス操作</strong><br>
                ・左ドラッグ: 階調(WW/WL)<br>
                ・中ドラッグ: 平行移動<br>
                ・右ドラッグ: ズーム<br>
                ・ホイール: コマ送り
            </div>

            <!-- ローディング表示 -->
            <div class="dcm-loader" id="dcmLoader">
                <div class="dcm-spinner"></div>
                <div style="font-size:0.85rem; color:#94a3b8;">DICOM画像を読み込み中...</div>
            </div>

            <!-- 画面下部 内視鏡病変強調ドック (IEE) -->
            <div class="iee-dock" id="ieeDock" title="内視鏡 病巣・微細血管強調モード">
                <span class="iee-label">🔬 病巣強調:</span>
                <button type="button" class="iee-btn active" data-mode="normal" title="通常表示 (白色光)">
                    🌈 通常
                </button>
                <button type="button" class="iee-btn" data-mode="lci" title="発赤強調 (LCI風): 早期胃癌・ポリープ・炎症の境界を赤と白の対比で極限まで浮き彫りにする">
                    🔴 発赤(LCI)
                </button>
                <button type="button" class="iee-btn" data-mode="nbi" title="血管強調 (NBI風): 青緑色光効果で微細血管や腺管ピットパターンを茶褐色に際立たせる">
                    🟢 血管(NBI)
                </button>
                <button type="button" class="iee-btn" data-mode="ihb" title="血流マップ (IHb): ヘモグロビン濃度を強調し充血・病巣部をサーモグラフィ風に可視化">
                    📊 血流マップ
                </button>
                <button type="button" class="iee-btn" data-mode="texture" title="凹凸・構造強調: 粘膜のひだ・境界・段差の輪郭をシャープに強調">
                    🏔️ 構造強調
                </button>
                <button type="button" class="iee-btn" data-mode="indigo" title="色素散布シミュレーション: インジゴカルミンを散布したかのように凹部の段差を強調">
                    🔵 色素散布
                </button>
            </div>

            <!-- サイドバー再オープンボタン -->
            <button type="button" class="sidebar-reopen-btn" id="sidebarReopenBtn" title="サムネイル一覧を開く">◀</button>
        </div>

        <!-- 右側・縦型サムネイルパネル -->
        <aside class="dcm-sidebar" id="dcmSidebar">
            <div class="sidebar-header">
                <span>サムネイル (<span id="thumbTotalCount">0</span>)</span>
                <button type="button" class="toggle-btn" id="btnToggleSidebar" title="パネルを閉じる">▶</button>
            </div>
            <div class="filmstrip-list" id="dcmFilmstripList">
                <!-- サムネイルカードが動的生成されます -->
            </div>
        </aside>
    </main>

    <!-- SVGフィルター定義 (内視鏡画像強調用: GPUハードウェアアクセラレーション) -->
    <svg style="display:none;" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <!-- ① LCI風・発赤強調 (赤色分離度最大化) -->
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

            <!-- ② 疑似NBI風・微細血管/ピット強調 (青緑光再マッピング) -->
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

            <!-- ③ ヘモグロビン血流マップ (IHb: 充血・新生血管サーモグラフィ) -->
            <filter id="iee-ihb" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    1.80 -0.80 -0.10 0 0.10
                   -0.40  1.40 -0.10 0 -0.05
                   -0.80 -0.20  1.80 0 -0.10
                    0     0     0    1 0" />
            </filter>

            <!-- ④ 凹凸・構造強調 (Texture / Edge Sharpening) -->
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
        </defs>
    </svg>

    <!-- トースト通知 -->
    <div id="dcmToast">メッセージ</div>

    <!-- ----------------------------------------------------------------------
         JavaScript制御ロジック
    ----------------------------------------------------------------------- -->
    <script>
        // 設定 & パラメータ
        const FOLDER_PATH = <?= json_encode($folder, JSON_UNESCAPED_UNICODE) ?>;
        const PAT_ID      = <?= json_encode($pat_id, JSON_UNESCAPED_UNICODE) ?>;
        const PAT_NAME    = <?= json_encode($pat_name, JSON_UNESCAPED_UNICODE) ?>;
        const EXAM_NAME   = <?= json_encode($exam_name, JSON_UNESCAPED_UNICODE) ?>;
        const EXAM_DATE   = <?= json_encode($exam_date, JSON_UNESCAPED_UNICODE) ?>;
        const EXAM_SINDAN = <?= json_encode($sindan, JSON_UNESCAPED_UNICODE) ?>;
        const IS_MODAL    = <?= json_encode($mode === 'modal') ?>;

        // DOM要素
        const stage = document.getElementById('dcmImageStage');
        const viewports = Array.from(stage.querySelectorAll('.dcm-viewport'));
        const loader = document.getElementById('dcmLoader');
        const filmstrip = document.getElementById('dcmFilmstripList');
        const sliceNavLabel = document.getElementById('sliceNavLabel');
        const thumbTotalCount = document.getElementById('thumbTotalCount');
        const navPrevBtn = document.getElementById('navPrevBtn');
        const navNextBtn = document.getElementById('navNextBtn');
        const floatPrevBtn = document.getElementById('floatPrevBtn');
        const floatNextBtn = document.getElementById('floatNextBtn');
        const toast = document.getElementById('dcmToast');

        const hudFilename = document.getElementById('hudFilename');
        const hudResolution = document.getElementById('hudResolution');
        const hudZoom = document.getElementById('hudZoom');
        const hudWwWl = document.getElementById('hudWwWl');

        const modeWwWlBtn = document.getElementById('modeWwWl');
        const modePanBtn = document.getElementById('modePan');
        const modeZoomBtn = document.getElementById('modeZoom');

        const btnFit = document.getElementById('btnFit');
        const btnOneToOne = document.getElementById('btnOneToOne');
        const btnInvert = document.getElementById('btnInvert');
        const btnReset = document.getElementById('btnReset');
        const btnDownloadZip = document.getElementById('btnDownloadZip');
        const btnDirectLaunch = document.getElementById('btnDirectLaunch');
        const btnPopout = document.getElementById('btnPopout');
        const btnCloseModal = document.getElementById('btnCloseModal');

        const sidebar = document.getElementById('dcmSidebar');
        const btnToggleSidebar = document.getElementById('btnToggleSidebar');
        const sidebarReopenBtn = document.getElementById('sidebarReopenBtn');

        // ビューワ内部ステート
        let dcmFiles = [];
        let currentIndex = 0;
        let currentLayout = 1; // 1, 2, or 4
        let zoom = 1.0;
        let panX = 0;
        let panY = 0;
        let contrast = 100;    // Window Width (WW) 相当 (100%基準)
        let brightness = 100;  // Window Level (WL) 相当 (100%基準)
        let isInverted = false;
        let activeMouseTool = 'ww_wl'; // 'ww_wl', 'pan', 'zoom'
        let activeIeeMode = 'normal';  // 'normal', 'lci', 'nbi', 'ihb', 'texture', 'indigo'

        // ドラッグ制御用
        let isDragging = false;
        let dragButton = 0; // 0: Left, 1: Middle, 2: Right
        let startMouseX = 0;
        let startMouseY = 0;
        let startPanX = 0;
        let startPanY = 0;
        let startZoom = 1.0;
        let startContrast = 100;
        let startBrightness = 100;

        // トースト表示
        function showToast(msg) {
            toast.textContent = msg;
            toast.className = 'show';
            setTimeout(() => { toast.className = ''; }, 2500);
        }

        // --------------------------------------------------------------------------
        // 初期化: DICOMファイルリスト取得
        // --------------------------------------------------------------------------
        async function initViewer() {
            if (!FOLDER_PATH) {
                alert("フォルダパスが指定されていません");
                return;
            }

            loader.style.display = 'flex';

            try {
                const res = await fetch(`dcm_api.php?action=list&folder=${encodeURIComponent(FOLDER_PATH)}`);
                if (!res.ok) throw new Error("HTTPエラー: " + res.status);
                const data = await res.json();

                if (data.status !== 'success' || !data.files || data.files.length === 0) {
                    alert("DICOM画像が見つかりませんでした: " + (data.message || 'ファイルが0件です'));
                    loader.style.display = 'none';
                    return;
                }

                dcmFiles = data.files;
                thumbTotalCount.textContent = dcmFiles.length;

                renderFilmstrip();
                loadSlice(0, true);

            } catch (err) {
                alert("DICOM読み込み通信エラー: " + err.message);
                loader.style.display = 'none';
            }
        }

        // サムネイルバーの生成 (縦並び)
        function renderFilmstrip() {
            filmstrip.innerHTML = '';
            dcmFiles.forEach((file, index) => {
                const card = document.createElement('div');
                card.className = 'thumb-card' + (index === currentIndex ? ' active' : '');
                card.dataset.index = index;

                const img = document.createElement('img');
                img.loading = 'lazy';
                img.src = `dcm_api.php?action=image&folder=${encodeURIComponent(FOLDER_PATH)}&file=${encodeURIComponent(file.filename)}&thumb=1`;
                img.alt = file.filename;

                const badge = document.createElement('span');
                badge.className = 'thumb-badge';
                badge.textContent = '#' + (index + 1);

                card.appendChild(img);
                card.appendChild(badge);

                card.addEventListener('click', () => {
                    loadSlice(index, false);
                });

                filmstrip.appendChild(card);
            });
        }

        // 指定スライスの画像読込
        // ナビラベル & ボタン状態更新
        function updateNavUI() {
            if (currentLayout === 1) {
                sliceNavLabel.textContent = `${currentIndex + 1} / ${dcmFiles.length}`;
            } else {
                const endIdx = Math.min(currentIndex + currentLayout, dcmFiles.length);
                sliceNavLabel.textContent = `${currentIndex + 1}-${endIdx} / ${dcmFiles.length}`;
            }
            navPrevBtn.disabled = (currentIndex === 0);
            navNextBtn.disabled = (currentIndex >= dcmFiles.length - 1);
            floatPrevBtn.disabled = (currentIndex === 0);
            floatNextBtn.disabled = (currentIndex >= dcmFiles.length - 1);
        }

        // サムネイルハイライト更新
        function updateFilmstripHighlight() {
            const cards = filmstrip.querySelectorAll('.thumb-card');
            cards.forEach((c, idx) => {
                const isVisible = (idx >= currentIndex && idx < currentIndex + currentLayout);
                c.classList.toggle('active', isVisible);
                if (idx === currentIndex) {
                    c.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        }

        // 画面レイアウト切替 (1面, 2面, 4面)
        function setLayout(layout) {
            currentLayout = layout;
            stage.className = `dcm-stage layout-${layout}`;
            document.querySelectorAll('.layout-btn').forEach(btn => {
                btn.classList.toggle('active', parseInt(btn.dataset.layout, 10) === layout);
            });
            updateNavUI();
            updateFilmstripHighlight();
            loadSlice(currentIndex, true);
            showToast(`🖥️ ${layout}画面表示に切り替えました`);
        }

        // 指定スライスの画像読込 (連番分割表示対応)
        function loadSlice(index, autoFit = false) {
            if (index < 0 || index >= dcmFiles.length) return;
            currentIndex = index;

            updateNavUI();
            updateFilmstripHighlight();

            const file0 = dcmFiles[currentIndex];
            hudFilename.textContent = file0.filename;

            loader.style.display = 'flex';

            let pendingCount = 0;
            for (let s = 0; s < 4; s++) {
                const vp = viewports[s];
                if (!vp) continue;
                if (s >= currentLayout) {
                    continue;
                }
                const fileIdx = currentIndex + s;
                const img = vp.querySelector('.dcm-slot-img');
                const badge = vp.querySelector('.slot-badge');

                if (fileIdx < dcmFiles.length) {
                    vp.classList.remove('is-empty');
                    if (badge) badge.textContent = '#' + (fileIdx + 1);
                    const fileObj = dcmFiles[fileIdx];
                    const imgUrl = `dcm_api.php?action=image&folder=${encodeURIComponent(FOLDER_PATH)}&file=${encodeURIComponent(fileObj.filename)}`;

                    if (s === 0) {
                        pendingCount++;
                        const tempImg = new Image();
                        tempImg.onload = () => {
                            img.src = imgUrl;
                            hudResolution.textContent = `${tempImg.naturalWidth} x ${tempImg.naturalHeight}`;
                            pendingCount--;
                            if (pendingCount <= 0) loader.style.display = 'none';

                            if (autoFit) {
                                fitToWindow();
                            } else {
                                updateTransform();
                            }
                        };
                        tempImg.onerror = () => {
                            pendingCount--;
                            if (pendingCount <= 0) loader.style.display = 'none';
                            showToast("⚠️ 画像の読み込みに失敗しました");
                        };
                        tempImg.src = imgUrl;
                    } else {
                        pendingCount++;
                        img.onload = () => {
                            pendingCount--;
                            if (pendingCount <= 0) loader.style.display = 'none';
                        };
                        img.onerror = () => {
                            pendingCount--;
                            if (pendingCount <= 0) loader.style.display = 'none';
                        };
                        img.src = imgUrl;
                    }
                } else {
                    vp.classList.add('is-empty');
                    img.src = '';
                }
            }
            if (pendingCount === 0) loader.style.display = 'none';
        }

        // 変形 (ズーム・パン) 反映 (全スロット完全同期)
        function updateTransform() {
            viewports.forEach(vp => {
                const wrapper = vp.querySelector('.dcm-img-wrapper');
                if (wrapper) {
                    wrapper.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
                }
            });
            hudZoom.textContent = Math.round(zoom * 100) + '%';
        }

        // 階調 (WW/WL) 反映 (全スロット完全同期)
        // 画面下部 内視鏡病変強調モード切替
        function setIeeMode(mode) {
            activeIeeMode = mode;
            document.querySelectorAll('.iee-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.mode === mode);
            });
            updateFilters();

            const names = {
                'normal': '🌈 通常表示 (白色光)',
                'lci': '🔴 発赤強調 (LCI風: 早期病変・炎症拾い上げ)',
                'nbi': '🟢 血管・ピット強調 (NBI風: 微細血管・腺管質的診断)',
                'ihb': '📊 血流マップ (ヘモグロビン濃度強調)',
                'texture': '🏔️ 凹凸・構造強調 (テクスチャ・境界シャープ化)',
                'indigo': '🔵 色素散布シミュレーション (インジゴカルミン風)'
            };
            showToast(`${names[mode] || mode} に切り替えました`);
        }

        // 階調 (WW/WL) & 内視鏡強調 (IEE) 反映 (全スロット完全同期)
        function updateFilters() {
            let filterStr = `contrast(${contrast}%) brightness(${brightness}%)`;
            if (isInverted) {
                filterStr += ' invert(100%)';
            }
            if (activeIeeMode !== 'normal') {
                filterStr += ` url(#iee-${activeIeeMode})`;
                if (activeIeeMode === 'lci') {
                    filterStr += ' saturate(135%)';
                } else if (activeIeeMode === 'nbi') {
                    filterStr += ' saturate(125%)';
                }
            }
            viewports.forEach(vp => {
                const img = vp.querySelector('.dcm-slot-img');
                if (img) {
                    img.style.filter = filterStr;
                }
            });
            const ieeTag = (activeIeeMode !== 'normal') ? ` [${activeIeeMode.toUpperCase()}]` : '';
            hudWwWl.textContent = `C:${Math.round(contrast)}% B:${Math.round(brightness)}%${ieeTag}`;
        }

        // 画面にフィット
        function fitToWindow() {
            const vp0 = viewports[0];
            const img0 = vp0.querySelector('.dcm-slot-img');
            if (!img0 || !img0.naturalWidth || !img0.naturalHeight) return;

            const slotW = vp0.clientWidth || (stage.clientWidth / (currentLayout === 1 ? 1 : 2));
            const slotH = vp0.clientHeight || (stage.clientHeight / (currentLayout === 4 ? 2 : 1));
            const imgW = img0.naturalWidth;
            const imgH = img0.naturalHeight;

            // 余白を持たせたフィット倍率を算出
            const padding = 12;
            const scaleX = (slotW - padding * 2) / imgW;
            const scaleY = (slotH - padding * 2) / imgH;
            zoom = Math.min(scaleX, scaleY, 1.0);

            // 中央寄せ
            panX = (slotW - imgW * zoom) / 2;
            panY = (slotH - imgH * zoom) / 2;

            updateTransform();
            showToast("⏹️ 画面にフィット");
        }

        // 原寸 (1:1)
        function oneToOne() {
            const vp0 = viewports[0];
            const img0 = vp0.querySelector('.dcm-slot-img');
            if (!img0 || !img0.naturalWidth || !img0.naturalHeight) return;

            zoom = 1.0;
            const slotW = vp0.clientWidth || (stage.clientWidth / (currentLayout === 1 ? 1 : 2));
            const slotH = vp0.clientHeight || (stage.clientHeight / (currentLayout === 4 ? 2 : 1));

            panX = (slotW - img0.naturalWidth) / 2;
            panY = (slotH - img0.naturalHeight) / 2;

            updateTransform();
            showToast("1:1 原寸表示");
        }

        // リセット
        function resetView() {
            contrast = 100;
            brightness = 100;
            isInverted = false;
            activeIeeMode = 'normal';
            document.querySelectorAll('.iee-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.mode === 'normal');
            });
            btnInvert.classList.remove('active-toggle');
            updateFilters();
            fitToWindow();
            showToast("🔄 表示をリセットしました");
        }

        // --------------------------------------------------------------------------
        // PACS標準マウス操作の実装
        // --------------------------------------------------------------------------
        stage.addEventListener('contextmenu', e => e.preventDefault()); // 右クリックメニュー抑止

        stage.addEventListener('mousedown', e => {
            isDragging = true;
            dragButton = e.button; // 0: Left, 1: Middle, 2: Right
            startMouseX = e.clientX;
            startMouseY = e.clientY;
            startPanX = panX;
            startPanY = panY;
            startZoom = zoom;
            startContrast = contrast;
            startBrightness = brightness;

            if (dragButton === 1 || activeMouseTool === 'pan') {
                stage.style.cursor = 'grabbing';
            } else if (dragButton === 2 || activeMouseTool === 'zoom') {
                stage.style.cursor = 'ns-resize';
            } else {
                stage.style.cursor = 'move';
            }
        });

        window.addEventListener('mousemove', e => {
            if (!isDragging) return;

            const dx = e.clientX - startMouseX;
            const dy = e.clientY - startMouseY;

            // ① 中ボタンドラッグ: 常に「パン (平行移動)」
            if (dragButton === 1 || (dragButton === 0 && activeMouseTool === 'pan')) {
                panX = startPanX + dx;
                panY = startPanY + dy;
                updateTransform();
                return;
            }

            // ② 右ボタンドラッグ: 常に「ズーム (拡大・縮小)」
            if (dragButton === 2 || (dragButton === 0 && activeMouseTool === 'zoom')) {
                // 上へドラッグで拡大、下へドラッグで縮小
                const zoomFactor = Math.exp(-dy * 0.008);
                const newZoom = Math.min(Math.max(startZoom * zoomFactor, 0.05), 10.0);

                // スロット中央基準ズーム
                const vp0 = viewports[0];
                const slotW = vp0.clientWidth || 400;
                const slotH = vp0.clientHeight || 400;
                const centerX = slotW / 2;
                const centerY = slotH / 2;

                panX = centerX - (centerX - startPanX) * (newZoom / startZoom);
                panY = centerY - (centerY - startPanY) * (newZoom / startZoom);
                zoom = newZoom;

                updateTransform();
                return;
            }

            // ③ 左ボタンドラッグ (標準モード): 「WW/WL (コントラスト/輝度)」
            if (dragButton === 0 && activeMouseTool === 'ww_wl') {
                // 水平ドラッグ: ウィンドウ幅(WW/コントラスト)
                contrast = Math.max(10, Math.min(400, startContrast + dx * 0.6));
                // 垂直ドラッグ: ウィンドウ値(WL/輝度) (上ドラッグで明るく)
                brightness = Math.max(10, Math.min(300, startBrightness - dy * 0.5));
                updateFilters();
            }
        });

        window.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                stage.style.cursor = 'crosshair';
            }
        });

        // 中央ホイール回転: 「スライス (コマ送り/戻し)」
        stage.addEventListener('wheel', e => {
            e.preventDefault();
            if (e.deltaY > 0) {
                // 下スクロール: 次コマ
                if (currentIndex < dcmFiles.length - 1) loadSlice(currentIndex + 1);
            } else if (e.deltaY < 0) {
                // 上スクロール: 前コマ
                if (currentIndex > 0) loadSlice(currentIndex - 1);
            }
        }, { passive: false });

        // ダブルクリック: フィット ⇔ 原寸 トグル
        stage.addEventListener('dblclick', () => {
            if (Math.abs(zoom - 1.0) < 0.05) {
                fitToWindow();
            } else {
                oneToOne();
            }
        });

        // --------------------------------------------------------------------------
        // ショートカットキー対応
        // --------------------------------------------------------------------------
        window.addEventListener('keydown', e => {
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === 'PageDown') {
                if (currentIndex < dcmFiles.length - 1) loadSlice(currentIndex + 1);
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp' || e.key === 'PageUp') {
                if (currentIndex > 0) loadSlice(currentIndex - 1);
            } else if (e.key === 'Home') {
                loadSlice(0);
            } else if (e.key === 'End') {
                loadSlice(dcmFiles.length - 1);
            } else if (e.key === 'f' || e.key === 'F') {
                fitToWindow();
            } else if (e.key === '1') {
                setLayout(1);
            } else if (e.key === '2') {
                setLayout(2);
            } else if (e.key === '4') {
                setLayout(4);
            } else if (e.key === 'o' || e.key === 'O') {
                oneToOne();
            } else if (e.key === 'r' || e.key === 'R') {
                resetView();
            } else if (e.key === 'i' || e.key === 'I') {
                btnInvert.click();
            } else if (e.key === 'Escape') {
                closeViewer();
            }
        });

        // --------------------------------------------------------------------------
        // UIボタンイベントリスナー
        // --------------------------------------------------------------------------
        document.querySelectorAll('.layout-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const l = parseInt(btn.dataset.layout, 10);
                setLayout(l);
            });
        });

        document.querySelectorAll('.iee-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                setIeeMode(btn.dataset.mode);
            });
        });
        navPrevBtn.addEventListener('click', () => { if (currentIndex > 0) loadSlice(currentIndex - 1); });
        navNextBtn.addEventListener('click', () => { if (currentIndex < dcmFiles.length - 1) loadSlice(currentIndex + 1); });
        floatPrevBtn.addEventListener('click', () => { if (currentIndex > 0) loadSlice(currentIndex - 1); });
        floatNextBtn.addEventListener('click', () => { if (currentIndex < dcmFiles.length - 1) loadSlice(currentIndex + 1); });

        // マウスモードボタングループ
        function setMouseMode(mode) {
            activeMouseTool = mode;
            modeWwWlBtn.classList.toggle('active', mode === 'ww_wl');
            modePanBtn.classList.toggle('active', mode === 'pan');
            modeZoomBtn.classList.toggle('active', mode === 'zoom');
            showToast(mode === 'ww_wl' ? "左ドラッグ: 階調 (WW/WL)" : mode === 'pan' ? "左ドラッグ: 平行移動 (パン)" : "左ドラッグ: ズーム");
        }
        modeWwWlBtn.addEventListener('click', () => setMouseMode('ww_wl'));
        modePanBtn.addEventListener('click', () => setMouseMode('pan'));
        modeZoomBtn.addEventListener('click', () => setMouseMode('zoom'));

        btnFit.addEventListener('click', fitToWindow);
        btnOneToOne.addEventListener('click', oneToOne);
        btnReset.addEventListener('click', resetView);

        // 白黒反転
        btnInvert.addEventListener('click', () => {
            isInverted = !isInverted;
            btnInvert.classList.toggle('active-toggle', isInverted);
            updateFilters();
            showToast(isInverted ? "🌓 白黒反転 有効" : "🌓 白黒反転 解除");
        });

        // サイドバー開閉
        btnToggleSidebar.addEventListener('click', () => {
            sidebar.classList.add('collapsed');
            sidebarReopenBtn.style.display = 'flex';
        });
        sidebarReopenBtn.addEventListener('click', () => {
            sidebar.classList.remove('collapsed');
            sidebarReopenBtn.style.display = 'none';
        });

        // 一括ZIP
        btnDownloadZip.addEventListener('click', () => {
            if (!FOLDER_PATH) return;
            showToast("📦 一括ZIPを生成中...");
            window.location.href = `dcm_api.php?action=zip&folder=${encodeURIComponent(FOLDER_PATH)}`;
        });

        // 外部ビューワ直接起動
        btnDirectLaunch.addEventListener('click', () => {
            if (!FOLDER_PATH) return;
            const formData = new URLSearchParams();
            formData.append('action', 'launch');
            formData.append('folder', FOLDER_PATH);
            formData.append('launch_mode', 'NORMAL');

            fetch('index.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        showToast("🚀 外部ビューワ起動トリガー送信完了");
                    } else {
                        alert("起動エラー: " + d.message);
                    }
                })
                .catch(e => alert("通信エラー: " + e.message));
        });

        // 別窓化 (ポップアウト)
        if (btnPopout) {
            btnPopout.addEventListener('click', () => {
                const params = new URLSearchParams(window.location.search);
                params.set('mode', 'standalone');
                const win = window.open(`dicom_view.php?${params.toString()}`, '_blank', 'width=1600,height=900,menubar=no,toolbar=no');
                if (win) {
                    closeViewer();
                }
            });
        }

        // クローズ処理
        function closeViewer() {
            if (IS_MODAL) {
                // 親ウィンドウに閉じるよう通知
                window.parent.postMessage({ action: 'closeDicom' }, '*');
            } else {
                window.close();
            }
        }
        if (btnCloseModal) {
            btnCloseModal.addEventListener('click', closeViewer);
        }

        // リサイズ時の自動追従
        window.addEventListener('resize', () => {
            fitToWindow();
        });

        // 起動開始
        initViewer();
    </script>
</body>
</html>
