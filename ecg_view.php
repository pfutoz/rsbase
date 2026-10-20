<?php
// ==============================================================================
// RS-Base 高性能心電図ビューワー (ecg_view.php)
// 12誘導安静時心電図・ミリ方眼紙リアルタイム描画・電子キャリパー計測
// ==============================================================================

$file      = $_GET['file'] ?? '';
$pat_id    = $_GET['id'] ?? '';
$pat_name  = $_GET['name'] ?? '';
$exam_name = $_GET['kensa'] ?? '心電図';
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
    <title>ECG Viewer - <?= htmlspecialchars($pat_name ? "{$pat_name} ({$pat_id})" : "RS-Base", ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --bg-base: #090d16;
            --bg-header: #0f172a;
            --border-color: #334155;
            --accent-cyan: #06b6d4;
            --accent-glow: rgba(6, 182, 212, 0.4);
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
           上部ヘッダー
        -------------------------------------------------------------------------- */
        .ecg-header {
            height: 52px;
            background: linear-gradient(180deg, #111827 0%, #0b0f19 100%);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 14px;
            z-index: 100;
            flex-shrink: 0;
            gap: 12px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .ecg-header::-webkit-scrollbar {
            display: none;
        }

        .ecg-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .badge-id {
            background: #0e7490;
            color: #cffafe;
            padding: 2px 7px;
            border-radius: 4px;
            font-weight: 700;
            font-family: monospace;
            font-size: 0.85rem;
            border: 1px solid #06b6d4;
        }

        .patient-name {
            font-weight: 700;
            color: #ffffff;
            font-size: 0.95rem;
        }

        .exam-badge {
            background: #047857;
            color: #a7f3d0;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid #10b981;
        }

        .exam-date {
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        /* ツールボタングループ */
        .ecg-tools {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-shrink: 0;
        }

        .tool-btn {
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
            font-weight: 500;
        }

        .tool-btn:hover {
            background: #334155;
            color: #ffffff;
            border-color: #64748b;
        }

        .tool-btn.active {
            background: #0891b2;
            border-color: #22d3ee;
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 0 10px var(--accent-glow);
        }

        .tool-btn.btn-close {
            background: #991b1b;
            border-color: #dc2626;
            color: #fef2f2;
            font-weight: 700;
        }
        .tool-btn.btn-close:hover {
            background: #b91c1c;
        }

        /* --------------------------------------------------------------------------
           メインキャンバスステージ
        -------------------------------------------------------------------------- */
        .ecg-stage-container {
            flex: 1;
            position: relative;
            background: #07090e;
            overflow: hidden;
            cursor: grab;
        }

        .ecg-stage-container.grabbing {
            cursor: grabbing;
        }

        .ecg-stage-container.caliper-mode {
            cursor: crosshair;
        }

        #ecgCanvas {
            position: absolute;
            left: 0;
            top: 0;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.7);
            border-radius: 4px;
            transform-origin: 0 0;
            will-change: transform;
        }

        /* 左下HUD */
        .ecg-hud {
            position: absolute;
            bottom: 12px;
            left: 12px;
            background: rgba(15, 23, 42, 0.85);
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
            gap: 12px;
        }

        .ecg-hud span strong {
            color: var(--accent-cyan);
        }

        /* キャリパー計測結果吹き出し */
        #caliperTooltip {
            position: absolute;
            display: none;
            background: rgba(15, 23, 42, 0.95);
            color: #ffffff;
            border: 1px solid var(--accent-cyan);
            border-radius: 6px;
            padding: 6px 12px;
            font-family: monospace;
            font-size: 0.78rem;
            pointer-events: none;
            z-index: 50;
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
            white-space: nowrap;
            line-height: 1.4;
        }

        /* ローディング */
        .ecg-loader {
            position: absolute;
            inset: 0;
            background: rgba(7, 9, 14, 0.8);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 12px;
            z-index: 60;
        }

        .ecg-spinner {
            width: 44px;
            height: 44px;
            border: 4px solid rgba(6, 182, 212, 0.2);
            border-top: 4px solid var(--accent-cyan);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* トースト */
        #ecgToast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(50px);
            background: rgba(15, 23, 42, 0.95);
            color: #ffffff;
            border: 1px solid var(--accent-cyan);
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 0.85rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
            opacity: 0;
            transition: all 0.25s ease;
            z-index: 999;
            pointer-events: none;
        }
        #ecgToast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
    </style>
</head>
<body>

    <!-- ----------------------------------------------------------------------
         上部ヘッダー
    ----------------------------------------------------------------------- -->
    <header class="ecg-header">
        <div class="ecg-meta">
            <span class="badge-id">ID: <?= htmlspecialchars($pat_id ?: '----', ENT_QUOTES, 'UTF-8') ?></span>
            <span class="patient-name"><?= htmlspecialchars($pat_name ?: '患者未指定', ENT_QUOTES, 'UTF-8') ?> 様</span>
            <span class="exam-badge">📈 12誘導心電図</span>
            <span class="exam-date" id="headerDateLabel"><?= htmlspecialchars($display_date, ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <!-- ツールボタン -->
        <div class="ecg-tools">
            <button type="button" class="tool-btn active" id="btnPanMode" title="ドラッグで平行移動 / ホイールでズーム">
                ✋ 移動/ズーム
            </button>
            <button type="button" class="tool-btn" id="btnCaliperMode" title="電子キャリパー (ドラッグして時間 ms と電圧 mV を計測)">
                📏 キャリパー
            </button>
            <button type="button" class="tool-btn" id="btnFit" title="画面に合わせる [Fキー / ダブルクリック]">
                ⏹️ フィット
            </button>
            <button type="button" class="tool-btn" id="btnOneToOne" title="原寸大 (1:1)">
                1:1
            </button>
            <button type="button" class="tool-btn" id="btnReset" title="表示リセット [Rキー]">
                🔄 リセット
            </button>
            <button type="button" class="tool-btn" id="btnCalWidth" onclick="toggleCalWidth()" title="較正パルス幅の切り替え (0.1s / 0.2s)">
                ⚡ 較正: <span id="labelCalWidth">0.1s</span>
            </button>
            <button type="button" class="tool-btn active" id="btnDriftFilter" onclick="toggleDriftFilter()" title="基線ドリフト除去フィルタのON/OFF">
                〰️ ドリフト補正: <span id="labelDriftFilter">ON</span>
            </button>
            <button type="button" class="tool-btn" id="btnLineWidth" onclick="cycleLineWidth()" title="波形の線幅を切り替え (0.8px / 1.0px / 1.2px)">
                ✏️ 線幅: <span id="labelLineWidth">1.0px</span>
            </button>
            <button type="button" class="tool-btn" id="btnLaunchFEV" title="フクダ電子 FEV-05 ビューワを直接起動">
                🚀
            </button>
            <?php if ($mode === 'modal'): ?>
                <button type="button" class="tool-btn" id="btnPopout" title="新しいウィンドウで大画面表示">
                    🪟 別窓
                </button>
                <button type="button" class="tool-btn btn-close" id="btnCloseModal" title="閉じる [Escキー]">
                    ✕
                </button>
            <?php endif; ?>
        </div>
    </header>

    <!-- ----------------------------------------------------------------------
         メインステージ
    ----------------------------------------------------------------------- -->
    <main class="ecg-stage-container" id="ecgStage">
        <canvas id="ecgCanvas" width="1024" height="704"></canvas>

        <!-- 左下HUD -->
        <div class="ecg-hud">
            <span>倍率: <strong id="hudZoom">100%</strong></span>
            <span>規格: <strong>25.0mm/s  10mm/mV</strong></span>
            <span>機器: <strong id="hudDevice">Fukuda Denshi</strong></span>
        </div>

        <!-- キャリパーツールチップ -->
        <div id="caliperTooltip">計測値</div>

        <!-- ローディング -->
        <div class="ecg-loader" id="ecgLoader">
            <div class="ecg-spinner"></div>
            <div style="font-size:0.85rem; color:#94a3b8;">心電図データを解析中...</div>
        </div>
    </main>

    <!-- トースト通知 -->
    <div id="ecgToast">メッセージ</div>

    <!-- ----------------------------------------------------------------------
         JavaScript制御ロジック
    ----------------------------------------------------------------------- -->
    <script>
        const FILE_PATH   = <?= json_encode($file, JSON_UNESCAPED_UNICODE) ?>;
        const PAT_ID      = <?= json_encode($pat_id, JSON_UNESCAPED_UNICODE) ?>;
        const PAT_NAME    = <?= json_encode($pat_name, JSON_UNESCAPED_UNICODE) ?>;
        const EXAM_DATE   = <?= json_encode($exam_date, JSON_UNESCAPED_UNICODE) ?>;
        const IS_MODAL    = <?= json_encode($mode === 'modal') ?>;

        const stage = document.getElementById('ecgStage');
        const canvas = document.getElementById('ecgCanvas');
        const ctx = canvas.getContext('2d');
        const loader = document.getElementById('ecgLoader');
        const toast = document.getElementById('ecgToast');
        const caliperTip = document.getElementById('caliperTooltip');
        const hudZoom = document.getElementById('hudZoom');
        const hudDevice = document.getElementById('hudDevice');

        // ツールボタン
        const btnPanMode = document.getElementById('btnPanMode');
        const btnCaliperMode = document.getElementById('btnCaliperMode');
        const btnFit = document.getElementById('btnFit');
        const btnOneToOne = document.getElementById('btnOneToOne');
        const btnReset = document.getElementById('btnReset');
        const btnLaunchFEV = document.getElementById('btnLaunchFEV');
        const btnPopout = document.getElementById('btnPopout');
        const btnCloseModal = document.getElementById('btnCloseModal');

        // ステート
        let ecgData = null;
        let zoom = 1.0;
        let panX = 0;
        let panY = 0;
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        let currentMode = 'pan'; // 'pan' or 'caliper'

        // キャリパー状態
        let caliperActive = false;
        let caliperStart = null; // { canvasX, canvasY }
        let caliperEnd = null;

        // 較正パルス幅・ドリフトフィルタ・線幅ステート
        let calPulseWidthSec = 0.1; // 0.1s (標準) または 0.2s
        let isDriftFilterOn = true; // ドリフト補正 ON (標準) または OFF
        let currentLineWidth = 1.0; // 0.8px, 1.0px, 1.2px
        const lineWidthOptions = [0.8, 1.0, 1.2];
        let lineWidthIdx = 1; // 1.0px (標準)

        function toggleCalWidth() {
            calPulseWidthSec = (calPulseWidthSec === 0.1 ? 0.2 : 0.1);
            const label = document.getElementById('labelCalWidth');
            if (label) label.textContent = calPulseWidthSec + 's';
            renderECGCanvas();
            showToast(`⚡ 較正パルス幅を ${calPulseWidthSec}秒 (${Math.round(calPulseWidthSec * 1000)}ms) に切り替えました`);
        }

        function toggleDriftFilter() {
            isDriftFilterOn = !isDriftFilterOn;
            const btn = document.getElementById('btnDriftFilter');
            const label = document.getElementById('labelDriftFilter');
            if (label) label.textContent = (isDriftFilterOn ? 'ON' : 'OFF');
            if (btn) btn.classList.toggle('active', isDriftFilterOn);
            renderECGCanvas();
            showToast(isDriftFilterOn ? '〰️ 基線ドリフト除去フィルタ: ON' : '〰️ 基線ドリフト除去フィルタ: OFF (RAW生波形)');
        }

        function cycleLineWidth() {
            lineWidthIdx = (lineWidthIdx + 1) % lineWidthOptions.length;
            currentLineWidth = lineWidthOptions[lineWidthIdx];
            const label = document.getElementById('labelLineWidth');
            if (label) label.textContent = currentLineWidth.toFixed(1) + 'px';
            renderECGCanvas();
            showToast(`✏️ 波形の線幅を ${currentLineWidth.toFixed(1)}px に変更しました`);
        }

        // 基線ドリフト除去フィルタ (高速O(N)プレフィックス累積平均・約0.7秒窓=350サンプル)
        function applyDriftFilter(wave) {
            if (!wave || wave.length < 50) return wave;
            const len = wave.length;
            const filtered = new Float32Array(len);
            const win = 350; // 0.7秒窓 (1拍分の低周波変動を平滑化)
            const halfWin = Math.floor(win / 2);

            const prefix = new Float64Array(len + 1);
            for (let i = 0; i < len; i++) {
                prefix[i + 1] = prefix[i] + wave[i];
            }

            for (let i = 0; i < len; i++) {
                const start = Math.max(0, i - halfWin);
                const end = Math.min(len, i + halfWin);
                const baseline = (prefix[end] - prefix[start]) / (end - start);
                filtered[i] = wave[i] - baseline;
            }
            return filtered;
        }

        function showToast(msg) {
            toast.textContent = msg;
            toast.className = 'show';
            setTimeout(() => { toast.className = ''; }, 2500);
        }

        // --------------------------------------------------------------------------
        // データ取得 & 初期化
        // --------------------------------------------------------------------------
        async function initECG() {
            if (!FILE_PATH) {
                alert("心電図ファイルパスが指定されていません");
                loader.style.display = 'none';
                return;
            }

            loader.style.display = 'flex';

            try {
                const res = await fetch(`ecg_api.php?file=${encodeURIComponent(FILE_PATH)}`);
                if (!res.ok) throw new Error("HTTPエラー: " + res.status);
                const json = await res.json();

                if (json.status !== 'success' || !json.data) {
                    alert("心電図データの解析に失敗しました: " + (json.message || 'データが空です'));
                    loader.style.display = 'none';
                    return;
                }

                ecgData = json.data;
                populateHeader();
                renderECGCanvas();
                fitToWindow();
                loader.style.display = 'none';

            } catch (err) {
                alert("通信エラー: " + err.message);
                loader.style.display = 'none';
            }
        }

        function formatExamDateNoSec(dtStr) {
            if (!dtStr) return '';
            return dtStr.replace(/^(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}\s+\d{1,2}:\d{2})(?::\d{2})?.*$/, '$1');
        }

        function populateHeader() {
            if (!ecgData) return;
            hudDevice.textContent = ecgData.device || 'Fukuda Denshi';

            if (ecgData.date) {
                const lbl = document.getElementById('headerDateLabel');
                if (lbl) lbl.textContent = formatExamDateNoSec(ecgData.date);
            }
        }

        // --------------------------------------------------------------------------
        // Canvas描画 (ミリ方眼紙 ＋ 12誘導波形)
        // --------------------------------------------------------------------------
        function renderECGCanvas() {
            if (!ecgData || !ecgData.leads) return;

            const w = canvas.width;
            const h = canvas.height;

            // 背景白
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, w, h);

            // ミリ方眼紙エリア (25mm/s, 10mm/mV)
            // 1mm = 4px, 5mm = 20px
            const gX0 = 20;
            const gY0 = 150;
            const gX1 = w - 20;
            const gY1 = h - 34;

            // 1mm 小マス (フクダ純正帳票に合わせた鮮明な淡いピンク)
            ctx.strokeStyle = '#fecdd3';
            ctx.lineWidth = 0.6;
            ctx.beginPath();
            for (let x = gX0; x <= gX1; x += 4) {
                ctx.moveTo(x, gY0); ctx.lineTo(x, gY1);
            }
            for (let y = gY0; y <= gY1; y += 4) {
                ctx.moveTo(gX0, y); ctx.lineTo(gX1, y);
            }
            ctx.stroke();

            // 5mm 大マス (はっきりした標準方眼サーモンピンク)
            ctx.strokeStyle = '#f87171';
            ctx.lineWidth = 0.9;
            ctx.beginPath();
            for (let x = gX0; x <= gX1; x += 20) {
                ctx.moveTo(x, gY0); ctx.lineTo(x, gY1);
            }
            for (let y = gY0; y <= gY1; y += 20) {
                ctx.moveTo(gX0, y); ctx.lineTo(gX1, y);
            }
            ctx.stroke();

            // 外枠
            ctx.strokeStyle = '#ef4444';
            ctx.lineWidth = 1.2;
            ctx.strokeRect(gX0, gY0, gX1 - gX0, gY1 - gY0);

            // ----------------------------------------------------------------------
            // ヘッダーテキスト描画 (整然とした配置・テキスト重なり防止)
            // ----------------------------------------------------------------------
            ctx.fillStyle = '#1e3a8a';
            ctx.font = 'bold 15px monospace';
            const displayDate = formatExamDateNoSec(ecgData.date || EXAM_DATE || '');
            const patTitle = `${displayDate}  12誘導安静時心電図  ID:${PAT_ID}  氏名:${PAT_NAME} 様`;
            ctx.fillText(patTitle, gX0, 26);

            const m = ecgData.measurements || {};

            // 左端: メタ情報
            ctx.fillStyle = '#64748b';
            ctx.font = '12px monospace';
            ctx.fillText('所属-1:', gX0, 50);
            ctx.fillText('所属-2:', gX0, 68);
            ctx.fillText('投薬情報:', gX0, 86);
            ctx.fillText('自覚症状:', gX0, 104);
            ctx.fillStyle = '#dc2626';
            ctx.font = 'bold 12px monospace';
            ctx.fillText('>>>医師の確認を要す<<<', gX0, 138);

            // 中央: 計測値リスト (整然と縦配置)
            const mX = gX0 + 170;
            ctx.fillStyle = '#0f172a';
            ctx.font = '12px monospace';
            const hrVal = m.hr ? `${m.hr}/分` : '--/分';
            const hrNote = (m.hr && m.hr < 60) ? '洞性徐脈' : ((m.hr && m.hr > 100) ? '洞性頻脈' : '正常洞調律');
            ctx.fillText(`心拍数= ${hrVal.padStart(6, ' ')}    ${hrNote}`, mX, 50);
            ctx.fillText(`R-R = ${m.rr_sec ? Number(m.rr_sec).toFixed(3) : (m.rr_ms ? (m.rr_ms/1000).toFixed(3) : '--')}秒`, mX, 68);
            ctx.fillText(`P-R = ${m.pr_sec ? Number(m.pr_sec).toFixed(3) : (m.pr_ms ? (m.pr_ms/1000).toFixed(3) : '--')}秒`, mX, 86);
            ctx.fillText(`QRS = ${m.qrs_sec ? Number(m.qrs_sec).toFixed(3) : (m.qrs_ms ? (m.qrs_ms/1000).toFixed(3) : '--')}秒`, mX, 104);
            ctx.fillText(`QT  = ${m.qt_sec ? Number(m.qt_sec).toFixed(3) : (m.qt_ms ? (m.qt_ms/1000).toFixed(3) : '--')}秒`, mX, 122);
            ctx.fillText(`QTcB/F= ${m.qtc_b || '--'}/${m.qtc_f || '--'}`, mX, 140);

            // 右側: 軸・所見ブロック (重ならないようX座標を十分に分離)
            const mX2 = mX + 260;
            ctx.fillText(`軸  = ${m.axis_deg !== undefined ? m.axis_deg : '--'}度`, mX2, 50);
            ctx.fillStyle = '#be185d';
            ctx.font = 'bold 12px sans-serif';
            ctx.fillText('【境界域-正常】 負荷-可', mX2, 86);
            ctx.fillStyle = '#0f172a';
            ctx.font = '12px sans-serif';
            ctx.fillText('コメント:', mX2, 104);

            // 右端案内
            ctx.fillStyle = '#64748b';
            ctx.font = '11px monospace';
            ctx.fillText('4 - 5  : L  V5', w - 140, 50);
            ctx.fillText('6 - 5  : L  V5', w - 140, 68);
            ctx.fillText('9 - 4 - 2', w - 140, 86);
            ctx.fillText('2 - 1 - 3', w - 140, 104);

            // ----------------------------------------------------------------------
            // 12誘導波形描画 (左右2列で方眼紙全体をバランスよく配置)
            // ----------------------------------------------------------------------
            const leads = ecgData.leads;
            const leftLeads = ['I', 'II', 'III', 'aVR', 'aVL', 'aVF'];
            const rightLeads = ['V1', 'V2', 'V3', 'V4', 'V5', 'V6'];

            const rowSpacing = 80;
            const baseYStart = gY0 + 50;

            const pxPerMv = 40.0; // 10mm/mV = 40px/mV
            const pxPerSample = 100.0 / 500.0; // 25mm/s (100px/s, 500Hz)

            // 較正パルス幅 (0.1秒 = 10px = 2.5mm / 0.2秒 = 20px = 5.0mm)
            const calPulseWidthPx = (calPulseWidthSec === 0.2 ? 20 : 10);

            // 較正パルス描画 (1mV = 40px, 黒色実線、基線と一体描画)
            function drawCalPulse(startX, cy) {
                ctx.strokeStyle = '#000000';
                ctx.lineWidth = currentLineWidth;
                ctx.beginPath();
                ctx.moveTo(startX, cy);
                ctx.lineTo(startX, cy - 40); // 1mV (10mm = 40px)
                ctx.lineTo(startX + calPulseWidthPx, cy - 40); // 0.1s (10px) または 0.2s (20px)
                ctx.lineTo(startX + calPulseWidthPx, cy);
                ctx.lineTo(startX + calPulseWidthPx + 6, cy);
                ctx.stroke();
                return startX + calPulseWidthPx + 6;
            }

            // 左列 6誘導 (I, II, III, aVR, aVL, aVF)
            const calStartX = gX0 + 8;
            const xLeft = calStartX + calPulseWidthPx + 8;
            const leftSampleCount = 2250; // 約4.5秒間

            leftLeads.forEach((name, idx) => {
                const cy = baseYStart + idx * rowSpacing;

                // 誘導名ラベル (パルス左下に青文字で重ならず配置)
                ctx.fillStyle = '#1d4ed8';
                ctx.font = 'bold 12px sans-serif';
                ctx.fillText(name, calStartX, cy + 14);

                // 較正パルス描画 (黒色実線)
                drawCalPulse(calStartX, cy);

                let rawWave = leads[name] || [];
                let wave = isDriftFilterOn ? applyDriftFilter(rawWave) : rawWave;
                const len = Math.min(leftSampleCount, wave.length);

                ctx.strokeStyle = '#000000';
                ctx.lineWidth = currentLineWidth;
                ctx.beginPath();
                for (let s = 0; s < len; s++) {
                    const px = xLeft + s * pxPerSample;
                    const py = cy - wave[s] * pxPerMv;
                    if (s === 0) ctx.moveTo(px, py);
                    else ctx.lineTo(px, py);
                }
                ctx.stroke();
            });

            // 右列 6誘導 (V1, V2, V3, V4, V5, V6)
            const gXMid = gX0 + (gX1 - gX0) / 2;
            const xRight = gXMid + 32;
            const rightSampleCount = 2250; // 約4.5秒間

            rightLeads.forEach((name, idx) => {
                const cy = baseYStart + idx * rowSpacing;

                // 誘導名ラベル
                ctx.fillStyle = '#1d4ed8';
                ctx.font = 'bold 12px sans-serif';
                ctx.fillText(name, gXMid + 6, cy + 14);

                let rawWave = leads[name] || [];
                let wave = isDriftFilterOn ? applyDriftFilter(rawWave) : rawWave;
                const len = Math.min(rightSampleCount, wave.length);

                ctx.strokeStyle = '#000000';
                ctx.lineWidth = currentLineWidth;
                ctx.beginPath();
                for (let s = 0; s < len; s++) {
                    const px = xRight + s * pxPerSample;
                    const py = cy - wave[s] * pxPerMv;
                    if (s === 0) ctx.moveTo(px, py);
                    else ctx.lineTo(px, py);
                }
                ctx.stroke();
            });

            // フッター注記
            ctx.fillStyle = '#1e3a8a';
            ctx.font = '11px sans-serif';
            const filterText = isDriftFilterOn ? 'フィルタ:HUM強,筋電,ドリフト(補正中)' : 'フィルタ:HUM強,筋電(ドリフトOFF/RAW)';
            ctx.fillText(`25.0mm/sec   10mm/mV   較正:${calPulseWidthSec}s(1mV)   線幅:${currentLineWidth.toFixed(1)}px   ${filterText}`, gX0 + 10, gY1 + 18);

            // フッター基準マーク (緑の小さな三角)
            ctx.fillStyle = '#10b981';
            ctx.beginPath();
            ctx.moveTo(gX0 + 310, gY1 + 10);
            ctx.lineTo(gX0 + 305, gY1 + 20);
            ctx.lineTo(gX0 + 315, gY1 + 20);
            ctx.fill();

            // キャリパー線描画 (アクティブ時)
            if (caliperStart && caliperEnd) {
                drawCaliperOverlay();
            }
        }

        // キャリパーの計測ライン描画
        function drawCaliperOverlay() {
            ctx.save();
            ctx.strokeStyle = '#06b6d4';
            ctx.lineWidth = 1.5;
            ctx.setLineDash([4, 4]);

            // 垂直ガイドライン (時間軸)
            ctx.beginPath();
            ctx.moveTo(caliperStart.x, 0); ctx.lineTo(caliperStart.x, canvas.height);
            ctx.moveTo(caliperEnd.x, 0);   ctx.lineTo(caliperEnd.x, canvas.height);
            ctx.stroke();

            // 水平ガイドライン (電圧軸)
            ctx.strokeStyle = '#ec4899';
            ctx.beginPath();
            ctx.moveTo(0, caliperStart.y); ctx.lineTo(canvas.width, caliperStart.y);
            ctx.moveTo(0, caliperEnd.y);   ctx.lineTo(canvas.width, caliperEnd.y);
            ctx.stroke();

            // 2点間の結線
            ctx.setLineDash([]);
            ctx.strokeStyle = '#eab308';
            ctx.lineWidth = 2.0;
            ctx.beginPath();
            ctx.moveTo(caliperStart.x, caliperStart.y);
            ctx.lineTo(caliperEnd.x, caliperEnd.y);
            ctx.stroke();

            // アンカーポイント
            ctx.fillStyle = '#eab308';
            ctx.beginPath(); ctx.arc(caliperStart.x, caliperStart.y, 4, 0, Math.PI*2); ctx.fill();
            ctx.beginPath(); ctx.arc(caliperEnd.x, caliperEnd.y, 4, 0, Math.PI*2); ctx.fill();

            ctx.restore();
        }

        // --------------------------------------------------------------------------
        // ズーム & パン制御
        // --------------------------------------------------------------------------
        function updateTransform() {
            canvas.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
            hudZoom.textContent = Math.round(zoom * 100) + '%';
        }

        function fitToWindow() {
            const stageW = stage.clientWidth;
            const stageH = stage.clientHeight;
            const canvasW = canvas.width;
            const canvasH = canvas.height;

            const padding = 20;
            const scaleX = (stageW - padding * 2) / canvasW;
            const scaleY = (stageH - padding * 2) / canvasH;
            zoom = Math.min(scaleX, scaleY, 1.5);

            panX = Math.round((stageW - canvasW * zoom) / 2);
            panY = Math.round((stageH - canvasH * zoom) / 2);
            updateTransform();
            showToast("⏹️ 画面にフィット");
        }

        function oneToOne() {
            zoom = 1.0;
            const stageW = stage.clientWidth;
            const stageH = stage.clientHeight;
            panX = Math.round((stageW - canvas.width) / 2);
            panY = Math.round((stageH - canvas.height) / 2);
            updateTransform();
            showToast("1:1 原寸大");
        }

        function resetView() {
            caliperStart = null;
            caliperEnd = null;
            caliperTip.style.display = 'none';
            renderECGCanvas();
            fitToWindow();
            showToast("🔄 表示をリセットしました");
        }

        window.addEventListener('resize', () => {
            if (!isDragging) {
                fitToWindow();
            }
        });

        // --------------------------------------------------------------------------
        // マウスインタラクション
        // --------------------------------------------------------------------------
        stage.addEventListener('mousedown', e => {
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;

            if (currentMode === 'caliper') {
                const rect = canvas.getBoundingClientRect();
                const cx = (e.clientX - rect.left) / zoom;
                const cy = (e.clientY - rect.top) / zoom;
                caliperStart = { x: cx, y: cy };
                caliperEnd = { x: cx, y: cy };
                caliperActive = true;
            } else {
                stage.classList.add('grabbing');
            }
        });

        window.addEventListener('mousemove', e => {
            if (!isDragging) return;

            if (currentMode === 'caliper' && caliperActive) {
                const rect = canvas.getBoundingClientRect();
                const cx = (e.clientX - rect.left) / zoom;
                const cy = (e.clientY - rect.top) / zoom;
                caliperEnd = { x: cx, y: cy };
                renderECGCanvas();

                // 時間差 (25mm/s: 100px = 1000ms => 1px = 10ms)
                const dxPx = Math.abs(caliperEnd.x - caliperStart.x);
                const dtMs = Math.round(dxPx * 10);
                const bpm = dtMs > 0 ? Math.round(60000 / dtMs) : '--';

                // 電圧差 (10mm/mV: 40px = 1.0mV => 1px = 0.025mV)
                const dyPx = Math.abs(caliperEnd.y - caliperStart.y);
                const dvMv = (dyPx * 0.025).toFixed(2);

                caliperTip.style.display = 'block';
                caliperTip.style.left = (e.clientX + 15) + 'px';
                caliperTip.style.top = (e.clientY + 15) + 'px';
                caliperTip.innerHTML = `⏱️ 時間: <strong>${dtMs} ms</strong> (${bpm} bpm)<br>⚡ 電位: <strong>${dvMv} mV</strong>`;
            } else {
                panX += (e.clientX - startX);
                panY += (e.clientY - startY);
                startX = e.clientX;
                startY = e.clientY;
                updateTransform();
            }
        });

        window.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                stage.classList.remove('grabbing');
                if (currentMode === 'caliper') {
                    caliperActive = false;
                }
            }
        });

        // ホイールズーム
        stage.addEventListener('wheel', e => {
            e.preventDefault();
            const factor = e.deltaY < 0 ? 1.15 : 0.88;
            const newZoom = Math.min(Math.max(zoom * factor, 0.2), 6.0);

            const rect = stage.getBoundingClientRect();
            const mouseX = e.clientX - rect.left;
            const mouseY = e.clientY - rect.top;

            panX = mouseX - (mouseX - panX) * (newZoom / zoom);
            panY = mouseY - (mouseY - panY) * (newZoom / zoom);
            zoom = newZoom;
            updateTransform();
        }, { passive: false });

        stage.addEventListener('dblclick', () => {
            if (Math.abs(zoom - 1.0) < 0.05) {
                fitToWindow();
            } else {
                oneToOne();
            }
        });

        // --------------------------------------------------------------------------
        // ボタンイベント
        // --------------------------------------------------------------------------
        btnPanMode.addEventListener('click', () => {
            currentMode = 'pan';
            btnPanMode.classList.add('active');
            btnCaliperMode.classList.remove('active');
            stage.classList.remove('caliper-mode');
            caliperTip.style.display = 'none';
            caliperStart = null;
            caliperEnd = null;
            renderECGCanvas();
            showToast("✋ 移動・ズームモード");
        });

        btnCaliperMode.addEventListener('click', () => {
            currentMode = 'caliper';
            btnCaliperMode.classList.add('active');
            btnPanMode.classList.remove('active');
            stage.classList.add('caliper-mode');
            showToast("📏 電子キャリパー: ドラッグして時間・電位を計測");
        });

        btnFit.addEventListener('click', fitToWindow);
        btnOneToOne.addEventListener('click', oneToOne);
        btnReset.addEventListener('click', resetView);

        // FEV-05直接起動
        btnLaunchFEV.addEventListener('click', () => {
            if (!FILE_PATH) return;
            const formData = new URLSearchParams();
            formData.append('action', 'launch');
            formData.append('folder', FILE_PATH);
            formData.append('launch_mode', 'ECG');

            fetch('index.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        showToast("🚀 フクダ電子 FEV-05 起動指示送信完了");
                    } else {
                        alert("起動エラー: " + d.message);
                    }
                })
                .catch(e => alert("通信エラー: " + e.message));
        });

        if (btnPopout) {
            btnPopout.addEventListener('click', () => {
                const params = new URLSearchParams(window.location.search);
                params.set('mode', 'standalone');
                const win = window.open(`ecg_view.php?${params.toString()}`, '_blank', 'width=1400,height=900,menubar=no,toolbar=no');
                if (win) closeViewer();
            });
        }

        function closeViewer() {
            if (IS_MODAL) {
                window.parent.postMessage({ action: 'closeECG' }, '*');
            } else {
                window.close();
            }
        }
        if (btnCloseModal) {
            btnCloseModal.addEventListener('click', closeViewer);
        }

        window.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeViewer();
            else if (e.key === 'f' || e.key === 'F') fitToWindow();
            else if (e.key === '1') oneToOne();
            else if (e.key === 'r' || e.key === 'R') resetView();
            else if (e.key === 'c' || e.key === 'C') btnCaliperMode.click();
        });

        window.addEventListener('resize', fitToWindow);

        // 開始
        initECG();
    </script>
</body>
</html>
