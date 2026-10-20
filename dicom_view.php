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
            height: 38px;
            background: linear-gradient(180deg, #111827 0%, #0b0f19 100%);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 8px;
            z-index: 100;
            flex-shrink: 0;
            gap: 6px;
        }

        .dcm-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex-shrink: 1;
            min-width: 0;
        }

        .badge-id {
            background: #1e3a8a;
            color: #bfdbfe;
            padding: 1px 6px;
            border-radius: 4px;
            font-weight: 700;
            font-family: monospace;
            font-size: 0.8rem;
            border: 1px solid #3b82f6;
            white-space: nowrap;
        }

        .patient-name {
            font-weight: 700;
            color: #ffffff;
            font-size: 0.88rem;
            white-space: nowrap;
        }

        .exam-badge {
            background: #065f46;
            color: #a7f3d0;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            border: 1px solid #10b981;
            white-space: nowrap;
        }

        .exam-date, .exam-sindan {
            color: var(--text-muted);
            font-size: 0.75rem;
            white-space: nowrap;
        }

        /* コマ送り・ナビゲーション */
        .dcm-slice-nav {
            display: flex;
            align-items: center;
            background: #1e293b;
            border: 1px solid #475569;
            border-radius: 5px;
            overflow: hidden;
            height: 28px;
            flex-shrink: 0;
        }

        .nav-btn {
            background: transparent;
            border: none;
            color: var(--text-main);
            padding: 0 7px;
            height: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
            font-size: 0.75rem;
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
            padding: 0 6px;
            font-family: monospace;
            font-size: 0.78rem;
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
            border-radius: 5px;
            overflow: hidden;
            padding: 1px;
            gap: 1px;
            flex-shrink: 0;
        }

        .layout-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 3px 6px;
            font-size: 0.75rem;
            border-radius: 3px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 2px;
            transition: all 0.15s;
            font-weight: 500;
            white-space: nowrap;
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
            border-radius: 5px;
            overflow: hidden;
            padding: 1px;
            gap: 1px;
            flex-shrink: 0;
        }

        .mode-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 3px 6px;
            font-size: 0.75rem;
            border-radius: 3px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s;
            font-weight: 500;
            white-space: nowrap;
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
            gap: 3px;
            flex-shrink: 0;
        }

        .action-btn {
            background: #1e293b;
            border: 1px solid #475569;
            color: #e2e8f0;
            padding: 3px 7px;
            font-size: 0.76rem;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s;
            height: 28px;
            white-space: nowrap;
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

        .action-btn.active {
            background: #0284c7;
            border-color: #38bdf8;
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
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
            height: calc(100vh - 38px);
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
            display: none !important;
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
            pointer-events: none; /* 下部ドックやツールバーへのクリック入力を遮断しない */
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
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 4px;
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid rgba(56, 189, 248, 0.35);
            border-bottom: none;
            border-radius: 12px 12px 0 0;
            backdrop-filter: blur(8px);
            padding: 2px 8px;
            box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.6);
            z-index: 60; /* ローダー(z-index:50)やHUDより前面に常時配置しクリック不能化を防止 */
            user-select: none;
        }

        .iee-label {
            font-size: 0.72rem;
            color: #7dd3fc;
            font-weight: 700;
            padding: 0 3px;
            display: flex;
            align-items: center;
            gap: 2px;
            white-space: nowrap;
        }

        .iee-btn {
            background: #1e293b;
            border: 1px solid #334155;
            color: #cbd5e1;
            padding: 2px 7px;
            font-size: 0.72rem;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 3px;
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
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.5);
            font-weight: 700;
        }

        @media (max-width: 960px) {
            .mouse-guide {
                display: none;
            }
        }

        /* 範囲指定・クリップボードコピー用 オーバーレイ */
        .crop-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 70;
            cursor: crosshair;
            user-select: none;
        }

        .crop-guide-pill {
            position: absolute;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid var(--accent-blue);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.7);
            color: #ffffff;
            padding: 8px 18px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 14px;
            pointer-events: auto;
            z-index: 75;
        }

        .crop-cancel-btn {
            background: rgba(239, 68, 68, 0.85);
            border: none;
            color: #ffffff;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s;
        }
        .crop-cancel-btn:hover {
            background: #dc2626;
        }

        .crop-box {
            position: absolute;
            border: 2px dashed #38bdf8;
            background: rgba(56, 189, 248, 0.15);
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.45);
            pointer-events: none;
            z-index: 72;
        }

        .crop-dim-badge {
            position: absolute;
            bottom: -26px;
            right: 0;
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid var(--accent-blue);
            color: #7dd3fc;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-family: monospace;
            white-space: nowrap;
        }

        /* キャプチャ画像プレビュー / フォールバックモーダル */
        .copy-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.78);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .copy-modal-card {
            background: #0f172a;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            max-width: 90vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.8);
            overflow: hidden;
        }

        .copy-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 16px;
            background: #1e293b;
            border-bottom: 1px solid var(--border-color);
        }

        .copy-modal-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #e2e8f0;
        }

        .copy-modal-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.1rem;
            cursor: pointer;
        }
        .copy-modal-close:hover {
            color: #ffffff;
        }

        .copy-modal-body {
            padding: 14px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .copy-modal-msg {
            font-size: 0.85rem;
            color: #94a3b8;
            margin: 0;
            text-align: center;
            line-height: 1.5;
        }

        .copy-preview-wrap {
            max-width: 80vw;
            max-height: 60vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000000;
            border: 1px solid #334155;
            border-radius: 6px;
            overflow: hidden;
        }

        .copy-preview-img {
            max-width: 100%;
            max-height: 60vh;
            object-fit: contain;
            display: block;
        }

        .copy-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 10px 16px;
            background: #1e293b;
            border-top: 1px solid var(--border-color);
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
            <button type="button" class="action-btn active" id="btnFit" title="全体フィット: 画像全体を枠内に収める [Fキー]">
                ⏹️全体
            </button>
            <button type="button" class="action-btn" id="btnOlympusFit" title="オリンパスフィット: 左側文字情報を除外し、円形観察領域を中心に画面一杯に拡大表示 [Oキー / ダブルクリック]">
                ⏹️O社
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
            <button type="button" class="action-btn" id="btnCopy" title="クリップボードにコピー (画面全体または範囲指定ドラッグ) [Cキー / Ctrl+C]">
                📋 コピー
            </button>
            <button type="button" class="action-btn" id="btnDownloadZip" title="JPEG一括ZIPダウンロード">
                📦
            </button>
            <button type="button" class="action-btn" id="btnDirectLaunch" title="外部DICOM専用ビューワを直接起動">
                🚀
            </button>
            <?php if ($mode === 'modal'): ?>
                <button type="button" class="action-btn" id="btnPopout" title="新しいウィンドウで大画面表示">
                    🪟
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

            <!-- 範囲指定・クリップボードコピー用 オーバーレイ -->
            <div class="crop-overlay" id="dcmCropOverlay" style="display: none;">
                <div class="crop-guide-pill" id="cropGuidePill">
                    <span>✂️ コピーしたい領域をドラッグ (クリックのみで全体コピー)</span>
                    <button type="button" class="crop-cancel-btn" id="cropCancelBtn" title="キャンセル [Esc]">✕</button>
                </div>
                <div class="crop-box" id="dcmCropBox" style="display: none;">
                    <span class="crop-dim-badge" id="cropDimBadge">0 × 0</span>
                </div>
            </div>
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
    <!-- 注意: display:none はブラウザ(Chrome/Edge)でレンダリングツリーから除外されフィルター解除やキャッシュ無効化に不具合を起こすため、不可視かつレンダリングツリーに残るスタイルを使用 -->
    <svg style="position: absolute; width: 0; height: 0; overflow: hidden; pointer-events: none;" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
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

    <!-- コピーフォールバック / プレビューモーダル -->
    <div class="copy-modal" id="copyFallbackModal" style="display: none;">
        <div class="copy-modal-card">
            <div class="copy-modal-header">
                <span class="copy-modal-title" id="copyModalTitle">📋 画像キャプチャ完了</span>
                <button type="button" class="copy-modal-close" id="copyModalCloseBtn">✕</button>
            </div>
            <div class="copy-modal-body">
                <p id="copyModalMsg" class="copy-modal-msg"></p>
                <div class="copy-preview-wrap">
                    <img id="copyPreviewImg" class="copy-preview-img" alt="Capture Preview">
                </div>
            </div>
            <div class="copy-modal-footer">
                <button type="button" class="action-btn" id="copyModalDownloadBtn">💾 PNG再保存</button>
                <button type="button" class="action-btn" id="copyModalOkBtn" style="background:#0284c7; border-color:#38bdf8; font-weight:700;">OK (閉じる)</button>
            </div>
        </div>
    </div>

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
        const btnOlympusFit = document.getElementById('btnOlympusFit');
        const btnOneToOne = document.getElementById('btnOneToOne');
        const btnInvert = document.getElementById('btnInvert');
        const btnReset = document.getElementById('btnReset');
        const btnCopy = document.getElementById('btnCopy');
        const btnDownloadZip = document.getElementById('btnDownloadZip');
        const btnDirectLaunch = document.getElementById('btnDirectLaunch');
        const btnPopout = document.getElementById('btnPopout');
        const btnCloseModal = document.getElementById('btnCloseModal');

        const dcmCropOverlay = document.getElementById('dcmCropOverlay');
        const dcmCropBox = document.getElementById('dcmCropBox');
        const cropDimBadge = document.getElementById('cropDimBadge');
        const cropCancelBtn = document.getElementById('cropCancelBtn');

        const copyFallbackModal = document.getElementById('copyFallbackModal');
        const copyModalTitle = document.getElementById('copyModalTitle');
        const copyModalMsg = document.getElementById('copyModalMsg');
        const copyPreviewImg = document.getElementById('copyPreviewImg');
        const copyModalCloseBtn = document.getElementById('copyModalCloseBtn');
        const copyModalDownloadBtn = document.getElementById('copyModalDownloadBtn');
        const copyModalOkBtn = document.getElementById('copyModalOkBtn');

        const sidebar = document.getElementById('dcmSidebar');
        const btnToggleSidebar = document.getElementById('btnToggleSidebar');
        const sidebarReopenBtn = document.getElementById('sidebarReopenBtn');
        const ieeDock = document.getElementById('ieeDock');

        // ビューワ内部ステート
        let dcmFiles = [];
        let currentIndex = 0;
        let currentLayout = 1; // 1, 2, or 4
        let currentSliceRequestId = 0; // 高速コマ送り時の競合・非同期順序崩れ防止用ID
        let zoom = 1.0;
        let panX = 0;
        let panY = 0;
        let contrast = 100;    // Window Width (WW) 相当 (100%基準)
        let brightness = 100;  // Window Level (WL) 相当 (100%基準)
        let isInverted = false;
        let activeMouseTool = 'ww_wl'; // 'ww_wl', 'pan', 'zoom'
        let activeIeeMode = 'normal';  // 'normal', 'lci', 'nbi', 'ihb', 'texture', 'indigo'
        let isOlympusFitMode = false;  // O社内視鏡フィットモードフラグ
        let isCropMode = false;        // 範囲指定コピーモードフラグ
        let isCropDragging = false;
        let cropStartX = 0;
        let cropStartY = 0;
        let lastCaptureBlob = null;
        let lastCaptureFilename = '';

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
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error("Non-JSON API response:", text);
                    alert("DICOM一覧の取得エラー: サーバー応答がJSON形式ではありません。\n" + text.slice(0, 150));
                    loader.style.display = 'none';
                    return;
                }

                if (!res.ok || data.status !== 'success' || !data.files || data.files.length === 0) {
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

            const thisRequestId = ++currentSliceRequestId;

            updateNavUI();
            updateFilmstripHighlight();

            const file0 = dcmFiles[currentIndex];
            if (hudFilename) hudFilename.textContent = file0.filename;

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
                            if (thisRequestId !== currentSliceRequestId) return; // 古いリクエストは破棄
                            img.src = imgUrl;
                            if (hudResolution) hudResolution.textContent = `${tempImg.naturalWidth} x ${tempImg.naturalHeight}`;
                            pendingCount--;
                            if (pendingCount <= 0) loader.style.display = 'none';

                            if (autoFit) {
                                applyActiveFit();
                            } else {
                                updateTransform();
                            }
                            updateFilters(); // 画像切替後も確実にフィルターを再適用
                        };
                        tempImg.onerror = () => {
                            if (thisRequestId !== currentSliceRequestId) return;
                            pendingCount--;
                            if (pendingCount <= 0) loader.style.display = 'none';
                            showToast("⚠️ 画像の読み込みに失敗しました");
                        };
                        tempImg.src = imgUrl;
                    } else {
                        pendingCount++;
                        const onSlotLoaded = () => {
                            if (thisRequestId !== currentSliceRequestId) return;
                            pendingCount--;
                            if (pendingCount <= 0) loader.style.display = 'none';
                            updateFilters();
                        };
                        img.onload = onSlotLoaded;
                        img.onerror = onSlotLoaded;
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
        // 画面下部 内視鏡病変強調モード切替 (同一モード再クリックで通常へトグル復帰可能)
        function setIeeMode(mode) {
            // すでにアクティブな強調モードを再度クリックした場合は通常表示にトグル復帰
            if (activeIeeMode === mode && mode !== 'normal') {
                mode = 'normal';
            }

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
            let filterParts = [];
            if (contrast !== 100) filterParts.push(`contrast(${contrast}%)`);
            if (brightness !== 100) filterParts.push(`brightness(${brightness}%)`);
            if (isInverted) filterParts.push('invert(100%)');

            if (activeIeeMode !== 'normal') {
                filterParts.push(`url(#iee-${activeIeeMode})`);
                if (activeIeeMode === 'lci') {
                    filterParts.push('saturate(135%)');
                } else if (activeIeeMode === 'nbi') {
                    filterParts.push('saturate(125%)');
                }
            }

            const filterStr = filterParts.length > 0 ? filterParts.join(' ') : 'none';

            viewports.forEach(vp => {
                const img = vp.querySelector('.dcm-slot-img');
                if (img) {
                    // ブラウザ(特にChromium/Edge)のSVGフィルター解除時キャッシュ固着を完全に防ぐため、
                    // 通常表示に戻る時、既存フィルターにurl(#iee-)が含まれていれば一旦強制クリア
                    if (activeIeeMode === 'normal' && (img.style.filter.indexOf('url(') !== -1 || filterStr === 'none')) {
                        img.style.filter = 'none';
                        void img.offsetHeight; // DOM再計算を強制フラッシュしてGPUシェーダーを完全破棄
                    }
                    img.style.filter = filterStr;
                }
            });
            const ieeTag = (activeIeeMode !== 'normal') ? ` [${activeIeeMode.toUpperCase()}]` : '';
            if (hudWwWl) {
                hudWwWl.textContent = `C:${Math.round(contrast)}% B:${Math.round(brightness)}%${ieeTag}`;
            }
        }

        // --------------------------------------------------------------------------
        // 標準全体フィット (富士フイルム・CT・一般画像用)
        // --------------------------------------------------------------------------
        function fitToWindow() {
            const vp0 = viewports[0];
            const img0 = vp0.querySelector('.dcm-slot-img');
            if (!img0 || !img0.naturalWidth || !img0.naturalHeight) return;

            const slotW = vp0.clientWidth || (stage.clientWidth / (currentLayout === 1 ? 1 : 2));
            const slotH = vp0.clientHeight || (stage.clientHeight / (currentLayout === 4 ? 2 : 1));
            const imgW = img0.naturalWidth;
            const imgH = img0.naturalHeight;

            // 余白ゼロで画面いっぱいにフィット (縦解像度最大化)
            const scaleX = slotW / imgW;
            const scaleY = slotH / imgH;
            zoom = Math.min(scaleX, scaleY, 1.0);

            // 中央寄せ
            panX = (slotW - imgW * zoom) / 2;
            panY = (slotH - imgH * zoom) / 2;

            isOlympusFitMode = false;
            if (btnFit) btnFit.classList.add('active');
            if (btnOlympusFit) btnOlympusFit.classList.remove('active');

            updateTransform();
            showToast("⏹️ 全体フィット");
        }

        // --------------------------------------------------------------------------
        // O社 (オリンパス) 内視鏡専用フィット
        // 左側の患者・スコープ情報テキスト領域 (左約35%) を除外し、
        // 円形観察視野 (右側約65%) を画面中央いっぱいに拡大フィット表示する
        // --------------------------------------------------------------------------
        function olympusFit() {
            const vp0 = viewports[0];
            const img0 = vp0.querySelector('.dcm-slot-img');
            if (!img0 || !img0.naturalWidth || !img0.naturalHeight) return;

            const slotW = vp0.clientWidth || (stage.clientWidth / (currentLayout === 1 ? 1 : 2));
            const slotH = vp0.clientHeight || (stage.clientHeight / (currentLayout === 4 ? 2 : 1));
            const imgW = img0.naturalWidth;
            const imgH = img0.naturalHeight;

            // オリンパス内視鏡の円形観察視野ジオメトリ:
            // X範囲: 0.35 * imgW 〜 1.0 * imgW (幅: 約 0.65 * imgW)
            // Y範囲: 0.05 * imgH 〜 0.95 * imgH (高さ: 約 0.90 * imgH)
            // 中心点: X = 0.675 * imgW, Y = 0.50 * imgH
            const circleW = imgW * 0.65;
            const circleH = imgH * 0.90;
            const centerX = imgW * 0.675;
            const centerY = imgH * 0.50;

            const scaleX = slotW / circleW;
            const scaleY = slotH / circleH;
            zoom = Math.min(scaleX, scaleY);

            // 円形観察視野の中心 (centerX, centerY) がスロットの中央 (slotW/2, slotH/2) に来るようパンを決定
            panX = (slotW / 2) - (centerX * zoom);
            panY = (slotH / 2) - (centerY * zoom);

            isOlympusFitMode = true;
            if (btnFit) btnFit.classList.remove('active');
            if (btnOlympusFit) btnOlympusFit.classList.add('active');

            updateTransform();
            showToast("⏹️ O社フィット");
        }

        function applyActiveFit() {
            if (isOlympusFitMode) {
                olympusFit();
            } else {
                fitToWindow();
            }
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

            if (btnFit) btnFit.classList.remove('active');
            if (btnOlympusFit) btnOlympusFit.classList.remove('active');

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

        // ダブルクリック: O社フィット ⇔ 全体フィット トグル
        stage.addEventListener('dblclick', () => {
            if (isOlympusFitMode) {
                fitToWindow();
            } else {
                olympusFit();
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
            } else if (e.key === 'o' || e.key === 'O') {
                olympusFit();
            } else if (e.key === '1') {
                setLayout(1);
            } else if (e.key === '2') {
                setLayout(2);
            } else if (e.key === '4') {
                setLayout(4);
            } else if (e.key === 'r' || e.key === 'R') {
                resetView();
            } else if ((e.ctrlKey || e.metaKey) && (e.key === 'c' || e.key === 'C')) {
                e.preventDefault();
                copyEntireImage();
            } else if (!e.ctrlKey && !e.metaKey && (e.key === 'c' || e.key === 'C')) {
                if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) return;
                e.preventDefault();
                toggleCropMode();
            } else if (e.key === '0' || e.key === 'n' || e.key === 'N') {
                setIeeMode('normal');
            } else if (e.key === 'i' || e.key === 'I') {
                btnInvert.click();
            } else if (e.key === 'Escape') {
                if (isCropMode) {
                    cancelCropMode();
                    return;
                }
                if (copyFallbackModal && copyFallbackModal.style.display !== 'none') {
                    closeCopyFallbackModal();
                    return;
                }
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

        if (ieeDock) {
            // ドック内のクリックやドラッグが画像ステージ(WW/WLドラッグ・ダブルクリック拡大・ホイールコマ送り)へ誤伝播するのを完全に遮断
            ieeDock.addEventListener('mousedown', e => e.stopPropagation());
            ieeDock.addEventListener('mouseup', e => e.stopPropagation());
            ieeDock.addEventListener('click', e => e.stopPropagation());
            ieeDock.addEventListener('dblclick', e => e.stopPropagation());
            ieeDock.addEventListener('wheel', e => e.stopPropagation());
        }

        document.querySelectorAll('.iee-btn').forEach(btn => {
            btn.addEventListener('click', e => {
                e.stopPropagation();
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
        if (btnOlympusFit) btnOlympusFit.addEventListener('click', olympusFit);
        btnOneToOne.addEventListener('click', oneToOne);
        btnReset.addEventListener('click', resetView);

        // --------------------------------------------------------------------------
        // 画像クリップボードコピー & 範囲切り出しロジック
        // --------------------------------------------------------------------------
        const MAT_LCI = [
            1.45, -0.30, -0.15, 0,  0.02,
           -0.20,  1.25, -0.05, 0, -0.02,
           -0.10, -0.20,  1.30, 0,  0.00,
            0,     0,     0,    1,  0
        ];
        const MAT_NBI = [
            0.35,  0.15,  0.05, 0, -0.05,
            0.10,  1.25,  0.15, 0,  0.00,
           -0.10,  0.10,  1.60, 0,  0.05,
            0,     0,     0,    1,  0
        ];
        const MAT_IHB = [
            1.80, -0.60, -0.20, 0,  0.05,
           -0.40,  1.40, -0.10, 0, -0.05,
           -0.80, -0.20,  1.80, 0, -0.10,
            0,     0,     0,    1,  0
        ];
        const KERNEL_TEXTURE = [
            -0.3, -0.6, -0.3,
            -0.6,  4.6, -0.6,
            -0.3, -0.6, -0.3
        ];
        const MAT_INDIGO = [
            0.85,  0.00,  0.00, 0, -0.05,
            0.00,  0.90,  0.00, 0, -0.02,
            0.10,  0.20,  1.30, 0,  0.10,
            0,     0,     0,    1,  0
        ];
        const KERNEL_INDIGO = [
            -0.2, -0.3, -0.2,
            -0.3,  3.0, -0.3,
            -0.2, -0.3, -0.2
        ];

        function applyColorMatrix(imageData, m) {
            const data = imageData.data;
            const len = data.length;
            const m0 = m[0], m1 = m[1], m2 = m[2], m4 = m[4] * 255;
            const m5 = m[5], m6 = m[6], m7 = m[7], m9 = m[9] * 255;
            const m10 = m[10], m11 = m[11], m12 = m[12], m14 = m[14] * 255;

            for (let i = 0; i < len; i += 4) {
                const r = data[i];
                const g = data[i + 1];
                const b = data[i + 2];

                const nr = m0 * r + m1 * g + m2 * b + m4;
                const ng = m5 * r + m6 * g + m7 * b + m9;
                const nb = m10 * r + m11 * g + m12 * b + m14;

                data[i]     = nr < 0 ? 0 : (nr > 255 ? 255 : nr);
                data[i + 1] = ng < 0 ? 0 : (ng > 255 ? 255 : ng);
                data[i + 2] = nb < 0 ? 0 : (nb > 255 ? 255 : nb);
            }
        }

        function applyKernel3x3(imageData, kernel) {
            const w = imageData.width;
            const h = imageData.height;
            const src = new Uint8ClampedArray(imageData.data);
            const dst = imageData.data;
            const [k0, k1, k2, k3, k4, k5, k6, k7, k8] = kernel;

            for (let y = 0; y < h; y++) {
                const ym1 = y > 0 ? y - 1 : 0;
                const yp1 = y < h - 1 ? y + 1 : h - 1;
                const rowSrcY   = y * w * 4;
                const rowSrcYm1 = ym1 * w * 4;
                const rowSrcYp1 = yp1 * w * 4;

                for (let x = 0; x < w; x++) {
                    const xm1 = x > 0 ? x - 1 : 0;
                    const xp1 = x < w - 1 ? x + 1 : w - 1;
                    const idx = (y * w + x) * 4;

                    const idx0 = rowSrcYm1 + xm1 * 4;
                    const idx1 = rowSrcYm1 + x * 4;
                    const idx2 = rowSrcYm1 + xp1 * 4;
                    const idx3 = rowSrcY + xm1 * 4;
                    const idx4 = rowSrcY + x * 4;
                    const idx5 = rowSrcY + xp1 * 4;
                    const idx6 = rowSrcYp1 + xm1 * 4;
                    const idx7 = rowSrcYp1 + x * 4;
                    const idx8 = rowSrcYp1 + xp1 * 4;

                    for (let c = 0; c < 3; c++) {
                        const val = (
                            k0 * src[idx0 + c] + k1 * src[idx1 + c] + k2 * src[idx2 + c] +
                            k3 * src[idx3 + c] + k4 * src[idx4 + c] + k5 * src[idx5 + c] +
                            k6 * src[idx6 + c] + k7 * src[idx7 + c] + k8 * src[idx8 + c]
                        );
                        dst[idx + c] = val < 0 ? 0 : (val > 255 ? 255 : val);
                    }
                }
            }
        }

        function applyIeeFilterToImageData(imageData, mode) {
            if (mode === 'lci') {
                applyColorMatrix(imageData, MAT_LCI);
            } else if (mode === 'nbi') {
                applyColorMatrix(imageData, MAT_NBI);
            } else if (mode === 'ihb') {
                applyColorMatrix(imageData, MAT_IHB);
            } else if (mode === 'texture') {
                applyKernel3x3(imageData, KERNEL_TEXTURE);
            } else if (mode === 'indigo') {
                applyColorMatrix(imageData, MAT_INDIGO);
                applyKernel3x3(imageData, KERNEL_INDIGO);
            }
        }

        function toggleCropMode() {
            if (isCropMode) {
                cancelCropMode();
            } else {
                startCropMode();
            }
        }

        function startCropMode() {
            isCropMode = true;
            btnCopy.classList.add('active');
            dcmCropOverlay.style.display = 'block';
            dcmCropBox.style.display = 'none';
            showToast("✂️ コピー領域をドラッグ、またはクリックで全体コピー");
        }

        function cancelCropMode() {
            isCropMode = false;
            isCropDragging = false;
            if (btnCopy) btnCopy.classList.remove('active');
            if (dcmCropOverlay) dcmCropOverlay.style.display = 'none';
            if (dcmCropBox) dcmCropBox.style.display = 'none';
        }

        function copyEntireImage() {
            executeCopy(null);
        }

        // 単一画像キャプチャ (原寸解像度 & フィルタ完全反映)
        function captureSingleImage(img, cropRect) {
            if (!img || !img.naturalWidth || !img.naturalHeight) return null;
            const nw = img.naturalWidth;
            const nh = img.naturalHeight;

            let sx = 0, sy = 0, sw = nw, sh = nh;
            if (cropRect && cropRect.width >= 10 && cropRect.height >= 10) {
                // ステージ座標から画像原寸座標への逆写像
                const rawX = (cropRect.x - panX) / zoom;
                const rawY = (cropRect.y - panY) / zoom;
                const rawW = cropRect.width / zoom;
                const rawH = cropRect.height / zoom;

                // 画像領域との交差クリッピング
                const clampLeft = Math.max(0, rawX);
                const clampTop = Math.max(0, rawY);
                const clampRight = Math.min(nw, rawX + rawW);
                const clampBottom = Math.min(nh, rawY + rawH);

                if (clampRight <= clampLeft || clampBottom <= clampTop) {
                    // 画像外ドラッグ時は全体コピー
                    showToast("⚠️ 選択範囲が画像外だったため画像全体をコピーします");
                    sx = 0; sy = 0; sw = nw; sh = nh;
                } else {
                    sx = Math.round(clampLeft);
                    sy = Math.round(clampTop);
                    sw = Math.round(clampRight - clampLeft);
                    sh = Math.round(clampBottom - clampTop);
                }
            }

            const canvas = document.createElement('canvas');
            canvas.width = sw;
            canvas.height = sh;
            const ctx = canvas.getContext('2d');

            // 階調 (WW/WL) および白黒反転フィルタ
            let filterParts = [];
            if (contrast !== 100) filterParts.push(`contrast(${contrast}%)`);
            if (brightness !== 100) filterParts.push(`brightness(${brightness}%)`);
            if (isInverted) filterParts.push('invert(100%)');
            if (activeIeeMode === 'lci') filterParts.push('saturate(135%)');
            if (activeIeeMode === 'nbi') filterParts.push('saturate(125%)');

            ctx.filter = filterParts.length > 0 ? filterParts.join(' ') : 'none';
            ctx.drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);

            // 内視鏡病変強調モード (IEE) のピクセル演算
            if (activeIeeMode !== 'normal') {
                const imgData = ctx.getImageData(0, 0, sw, sh);
                applyIeeFilterToImageData(imgData, activeIeeMode);
                ctx.putImageData(imgData, 0, 0);
            }

            return canvas;
        }

        // 複数画面レイアウトキャプチャ (2画面・4画面比較画像)
        function captureMultiLayoutImage(cropRect) {
            const stageW = stage.clientWidth;
            const stageH = stage.clientHeight;
            if (stageW <= 0 || stageH <= 0) return null;

            const compCanvas = document.createElement('canvas');
            compCanvas.width = stageW;
            compCanvas.height = stageH;
            const ctx = compCanvas.getContext('2d');

            ctx.fillStyle = '#05070c';
            ctx.fillRect(0, 0, stageW, stageH);

            const stRect = stage.getBoundingClientRect();

            for (let s = 0; s < currentLayout; s++) {
                const vp = viewports[s];
                if (!vp) continue;
                const vpRect = vp.getBoundingClientRect();
                const vx = vpRect.left - stRect.left;
                const vy = vpRect.top - stRect.top;
                const vw = vpRect.width;
                const vh = vpRect.height;

                ctx.save();
                ctx.beginPath();
                ctx.rect(vx, vy, vw, vh);
                ctx.clip();

                ctx.fillStyle = '#0b0f19';
                ctx.fillRect(vx, vy, vw, vh);

                const img = vp.querySelector('.dcm-slot-img');
                const fileIdx = currentIndex + s;

                if (img && img.naturalWidth && !vp.classList.contains('is-empty')) {
                    const singleCanvas = captureSingleImage(img, null);
                    if (singleCanvas) {
                        const destX = vx + panX;
                        const destY = vy + panY;
                        const destW = img.naturalWidth * zoom;
                        const destH = img.naturalHeight * zoom;
                        ctx.drawImage(singleCanvas, destX, destY, destW, destH);
                    }
                } else {
                    ctx.fillStyle = '#64748b';
                    ctx.font = '14px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText('(画像なし)', vx + vw / 2, vy + vh / 2);
                }

                ctx.strokeStyle = 'rgba(255, 255, 255, 0.12)';
                ctx.lineWidth = 1;
                ctx.strokeRect(vx, vy, vw, vh);

                ctx.restore();
            }

            if (cropRect && cropRect.width >= 10 && cropRect.height >= 10) {
                const cropCanvas = document.createElement('canvas');
                cropCanvas.width = cropRect.width;
                cropCanvas.height = cropRect.height;
                const cropCtx = cropCanvas.getContext('2d');
                cropCtx.drawImage(compCanvas, cropRect.x, cropRect.y, cropRect.width, cropRect.height, 0, 0, cropRect.width, cropRect.height);
                return cropCanvas;
            }

            return compCanvas;
        }

        function executeCopy(cropRect) {
            let canvas = null;
            const isFull = !cropRect;
            if (currentLayout === 1) {
                const vp0 = viewports[0];
                const img0 = vp0 ? vp0.querySelector('.dcm-slot-img') : null;
                if (!img0 || !img0.naturalWidth) {
                    showToast("⚠️ コピー対象の画像がありません");
                    return;
                }
                canvas = captureSingleImage(img0, cropRect);
            } else {
                canvas = captureMultiLayoutImage(cropRect);
            }

            if (canvas) {
                copyCanvasToClipboard(canvas, isFull);
            }
        }

        function getCaptureFilename(isFullImage) {
            const pad = (n) => String(n).padStart(2, '0');
            const now = new Date();
            const ts = `${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
            const idPart = PAT_ID ? `_${PAT_ID}` : '';
            const slicePart = `_${currentIndex + 1}`;
            const ieePart = (activeIeeMode !== 'normal') ? `_${activeIeeMode}` : '';
            const cropPart = isFullImage ? '' : '_crop';
            return `dicom_capture${idPart}${slicePart}${ieePart}${cropPart}_${ts}.png`;
        }

        function copyCanvasToClipboard(canvas, isFullImage) {
            if (!canvas) {
                showToast("⚠️ コピー対象画像が生成できませんでした");
                return;
            }

            const filename = getCaptureFilename(isFullImage);
            const dataUrl = canvas.toDataURL('image/png');

            canvas.toBlob(async blob => {
                if (!blob) {
                    showToast("⚠️ Blob生成に失敗しました");
                    return;
                }

                lastCaptureBlob = blob;
                lastCaptureFilename = filename;

                let writeOk = false;
                if (navigator.clipboard && typeof navigator.clipboard.write === 'function' && window.ClipboardItem) {
                    try {
                        const item = new ClipboardItem({ 'image/png': blob });
                        await navigator.clipboard.write([item]);
                        writeOk = true;
                    } catch (err) {
                        console.warn("Clipboard API blocked (HTTP LAN / security restrictions):", err);
                        writeOk = false;
                    }
                }

                if (writeOk) {
                    showToast("📋 クリップボードに画像をコピーしました！");
                } else {
                    // LAN環境・非HTTPSでのフォールバック (自動ダウンロード + プレビューモーダル)
                    triggerBlobDownload(blob, filename);
                    openCopyFallbackModal(dataUrl, filename);
                    showToast("💾 画像を保存しました (右クリックでコピーも可能)");
                }
            }, 'image/png');
        }

        function triggerBlobDownload(blob, filename) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 5000);
        }

        function openCopyFallbackModal(dataUrl, filename) {
            copyModalTitle.textContent = "📋 画像キャプチャ完了";
            copyModalMsg.innerHTML = "セキュリティ制限 (非HTTPS環境) のため、クリップボードへの直接書き込みが制限されました。<br><strong>画像を自動保存 (ダウンロード)</strong> しました。<br>または下の画像を右クリックして<strong>「画像をコピー」</strong>してください。";
            copyPreviewImg.src = dataUrl;
            copyFallbackModal.style.display = 'flex';
        }

        function closeCopyFallbackModal() {
            copyFallbackModal.style.display = 'none';
        }

        // 範囲指定オーバーレイのドラッグイベント
        dcmCropOverlay.addEventListener('mousedown', e => {
            if (e.target.closest('#cropCancelBtn')) return;
            e.preventDefault();
            e.stopPropagation();

            const stRect = stage.getBoundingClientRect();
            isCropDragging = true;
            cropStartX = e.clientX - stRect.left;
            cropStartY = e.clientY - stRect.top;

            dcmCropBox.style.left = cropStartX + 'px';
            dcmCropBox.style.top = cropStartY + 'px';
            dcmCropBox.style.width = '0px';
            dcmCropBox.style.height = '0px';
            dcmCropBox.style.display = 'block';
            cropDimBadge.textContent = '0 × 0';
        });

        dcmCropOverlay.addEventListener('mousemove', e => {
            if (!isCropDragging) return;
            e.preventDefault();
            e.stopPropagation();

            const stRect = stage.getBoundingClientRect();
            const curX = Math.max(0, Math.min(stRect.width, e.clientX - stRect.left));
            const curY = Math.max(0, Math.min(stRect.height, e.clientY - stRect.top));

            const x = Math.min(cropStartX, curX);
            const y = Math.min(cropStartY, curY);
            const w = Math.abs(curX - cropStartX);
            const h = Math.abs(curY - cropStartY);

            dcmCropBox.style.left = x + 'px';
            dcmCropBox.style.top = y + 'px';
            dcmCropBox.style.width = w + 'px';
            dcmCropBox.style.height = h + 'px';

            if (currentLayout === 1) {
                const natW = Math.round(w / zoom);
                const natH = Math.round(h / zoom);
                cropDimBadge.textContent = `${w} × ${h} px (原寸: ${natW} × ${natH})`;
            } else {
                cropDimBadge.textContent = `${w} × ${h} px`;
            }
        });

        dcmCropOverlay.addEventListener('mouseup', e => {
            if (!isCropDragging) return;
            e.preventDefault();
            e.stopPropagation();
            isCropDragging = false;

            const stRect = stage.getBoundingClientRect();
            const curX = Math.max(0, Math.min(stRect.width, e.clientX - stRect.left));
            const curY = Math.max(0, Math.min(stRect.height, e.clientY - stRect.top));

            const x = Math.min(cropStartX, curX);
            const y = Math.min(cropStartY, curY);
            const w = Math.abs(curX - cropStartX);
            const h = Math.abs(curY - cropStartY);

            cancelCropMode();

            if (w < 10 || h < 10) {
                // ドラッグ距離が小さい場合は画面全体コピー
                executeCopy(null);
            } else {
                // 範囲指定コピー
                executeCopy({ x, y, width: w, height: h });
            }
        });

        cropCancelBtn.addEventListener('click', e => {
            e.stopPropagation();
            cancelCropMode();
            showToast("コピーをキャンセルしました");
        });

        btnCopy.addEventListener('click', () => {
            toggleCropMode();
        });

        copyModalCloseBtn.addEventListener('click', closeCopyFallbackModal);
        copyModalOkBtn.addEventListener('click', closeCopyFallbackModal);
        copyModalDownloadBtn.addEventListener('click', () => {
            if (lastCaptureBlob) {
                triggerBlobDownload(lastCaptureBlob, lastCaptureFilename || 'dicom_capture.png');
            }
        });
        copyFallbackModal.addEventListener('click', e => {
            if (e.target === copyFallbackModal) closeCopyFallbackModal();
        });

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
                .then(r => r.text())
                .then(text => {
                    let d;
                    try { d = JSON.parse(text); } catch(err) { throw new Error(text.slice(0, 100)); }
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
            applyActiveFit();
        });

        // 起動開始
        initViewer();
    </script>
</body>
</html>
