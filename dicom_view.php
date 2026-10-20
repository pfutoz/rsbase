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

        /* メイン画像ステージ */
        .dcm-stage {
            flex: 1;
            height: 100%;
            position: relative;
            background: radial-gradient(circle at center, #0f172a 0%, #05070c 100%);
            overflow: hidden;
            cursor: crosshair;
        }

        .dcm-img-wrapper {
            position: absolute;
            left: 0;
            top: 0;
            transform-origin: 0 0;
            will-change: transform;
            pointer-events: none;
        }

        #dcmMainImage {
            display: block;
            max-width: none;
            pointer-events: none;
            transition: filter 0.05s ease-out;
        }

        #dcmMainImage.inverted {
            filter: invert(100%);
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

        /* トースト通知 */
        #dcmToast {
            position: fixed;
            bottom: 24px;
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
            <span class="exam-date"><?= htmlspecialchars($exam_date, ENT_QUOTES, 'UTF-8') ?></span>
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
        <div class="dcm-stage" id="dcmImageStage">
            <div class="dcm-img-wrapper" id="dcmImgWrapper">
                <img id="dcmMainImage" src="" alt="DICOM Main" draggable="false">
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
        const imgWrapper = document.getElementById('dcmImgWrapper');
        const mainImg = document.getElementById('dcmMainImage');
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
        let zoom = 1.0;
        let panX = 0;
        let panY = 0;
        let contrast = 100;    // Window Width (WW) 相当 (100%基準)
        let brightness = 100;  // Window Level (WL) 相当 (100%基準)
        let isInverted = false;
        let activeMouseTool = 'ww_wl'; // 'ww_wl', 'pan', 'zoom'

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
        function loadSlice(index, autoFit = false) {
            if (index < 0 || index >= dcmFiles.length) return;
            currentIndex = index;

            // ラベル・ボタン状態更新
            sliceNavLabel.textContent = `${currentIndex + 1} / ${dcmFiles.length}`;
            navPrevBtn.disabled = (currentIndex === 0);
            navNextBtn.disabled = (currentIndex === dcmFiles.length - 1);
            floatPrevBtn.disabled = (currentIndex === 0);
            floatNextBtn.disabled = (currentIndex === dcmFiles.length - 1);

            // サムネイルアクティブ枠更新 & スクロール
            const cards = filmstrip.querySelectorAll('.thumb-card');
            cards.forEach((c, idx) => {
                if (idx === currentIndex) {
                    c.classList.add('active');
                    c.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    c.classList.remove('active');
                }
            });

            const file = dcmFiles[currentIndex];
            hudFilename.textContent = file.filename;

            loader.style.display = 'flex';

            const imgUrl = `dcm_api.php?action=image&folder=${encodeURIComponent(FOLDER_PATH)}&file=${encodeURIComponent(file.filename)}`;
            const tempImg = new Image();
            tempImg.onload = () => {
                mainImg.src = imgUrl;
                hudResolution.textContent = `${tempImg.naturalWidth} x ${tempImg.naturalHeight}`;
                loader.style.display = 'none';

                if (autoFit) {
                    fitToWindow();
                } else {
                    updateTransform();
                }
            };
            tempImg.onerror = () => {
                loader.style.display = 'none';
                showToast("⚠️ 画像の読み込みに失敗しました");
            };
            tempImg.src = imgUrl;
        }

        // --------------------------------------------------------------------------
        // 変形 (ズーム・パン) & 階調 (WW/WL) 反映
        // --------------------------------------------------------------------------
        function updateTransform() {
            imgWrapper.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
            hudZoom.textContent = Math.round(zoom * 100) + '%';
        }

        function updateFilters() {
            let filterStr = `contrast(${contrast}%) brightness(${brightness}%)`;
            if (isInverted) {
                filterStr += ' invert(100%)';
            }
            mainImg.style.filter = filterStr;
            hudWwWl.textContent = `C:${Math.round(contrast)}% B:${Math.round(brightness)}%`;
        }

        // 画面にフィット
        function fitToWindow() {
            if (!mainImg.naturalWidth || !mainImg.naturalHeight) return;

            const stageW = stage.clientWidth;
            const stageH = stage.clientHeight;
            const imgW = mainImg.naturalWidth;
            const imgH = mainImg.naturalHeight;

            // 余白を持たせたフィット倍率を算出
            const padding = 20;
            const scaleX = (stageW - padding * 2) / imgW;
            const scaleY = (stageH - padding * 2) / imgH;
            zoom = Math.min(scaleX, scaleY, 1.0); // 原寸より大きくなりすぎないように調整

            // 中央寄せ
            panX = (stageW - imgW * zoom) / 2;
            panY = (stageH - imgH * zoom) / 2;

            updateTransform();
            showToast("⏹️ 画面にフィット");
        }

        // 原寸 (1:1)
        function oneToOne() {
            if (!mainImg.naturalWidth || !mainImg.naturalHeight) return;
            zoom = 1.0;
            const stageW = stage.clientWidth;
            const stageH = stage.clientHeight;
            panX = (stageW - mainImg.naturalWidth) / 2;
            panY = (stageH - mainImg.naturalHeight) / 2;
            updateTransform();
            showToast("1:1 原寸表示");
        }

        // リセット
        function resetView() {
            contrast = 100;
            brightness = 100;
            isInverted = false;
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
                const newZoom = Math.min(Math.max(startZoom * zoomFactor, 0.1), 10.0);

                // マウス中心ズーム
                const stageRect = stage.getBoundingClientRect();
                const mouseStageX = startMouseX - stageRect.left;
                const mouseStageY = startMouseY - stageRect.top;

                panX = mouseStageX - (mouseStageX - startPanX) * (newZoom / startZoom);
                panY = mouseStageY - (mouseStageY - startPanY) * (newZoom / startZoom);
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
