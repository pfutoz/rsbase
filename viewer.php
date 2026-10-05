<?php
// --- viewer.php (フラグ方式・RSView.exe 連携) ---
$host = '192.168.1.164';
$dbname = 'gazouDB';
$user = 'postgres';
$password = 'medicalin';
$port = '5432';

$trigger_file = __DIR__ . '/trigger.txt';

// ① 常駐スクリプトからのリセット要求 (reset=1) または 起動指令の書き込み処理
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    file_put_contents($trigger_file, ''); // フラグを空にする
    exit;
}

// 非同期からの起動フラグ書き込みリクエスト (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'launch') {
    $folder = $_POST['folder'] ?? '';
    if (!empty($folder)) {
        // trigger.txt に開きたいパスを書き込むだけでフラグON
        if (file_put_contents($trigger_file, $folder) !== false) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'フラグの書き込みに失敗しました。']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'フォルダパスが不正です。']);
    }
    exit;
}

$patient_id  = $_GET['patient_no'] ?? $_GET['id'] ?? '';
$folder_path = $_GET['folder'] ?? '';

$record = null;
$error_message = '';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $pdo->exec("SET client_encoding TO 'SHIFT_JIS'");

    if (!empty($patient_id)) {
        $stmt = $pdo->prepare("SELECT * FROM gazou_tbl WHERE id = ? ORDER BY date DESC LIMIT 1");
        $stmt->execute([$patient_id]);
        $raw = $stmt->fetch();
        if ($raw) {
            $raw_folder = $raw['folder'] ?? '';
            $unc_folder = str_replace('/', '\\', $raw_folder);
            
            $record = [
                'id'          => $raw['id'],
                'kanji'       => mb_convert_encoding($raw['kanja_kanji'] ?? '', 'UTF-8', 'CP932'),
                'yomi'        => mb_convert_encoding($raw['kanja_yomi'] ?? '', 'UTF-8', 'CP932'),
                'kensamei'    => mb_convert_encoding($raw['kensamei'] ?? '', 'UTF-8', 'CP932'),
                'date'        => $raw['date'],
                'sindan1'     => mb_convert_encoding($raw['sindan1'] ?? '', 'UTF-8', 'CP932'),
                'folder'      => $unc_folder
            ];
        }
    } elseif (!empty($folder_path)) {
        $record = [
            'id'          => '不明',
            'kanji'       => '不明',
            'kensamei'    => '直接指定',
            'date'        => '',
            'sindan1'     => '',
            'folder'      => str_replace('/', '\\', $folder_path)
        ];
    }
} catch (PDOException $e) {
    $error_message = "DB接続エラー: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>RSView 連携ビューワ (フラグ制御方式)</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        header { background: #1e293b; padding: 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        h1 { margin: 0; font-size: 1.2rem; color: #38bdf8; }
        .info-card { background: #1e293b; padding: 20px; border-radius: 8px; margin-bottom: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .info-item label { display: block; font-size: 0.75rem; color: #94a3b8; margin-bottom: 4px; }
        .info-item div { font-size: 1rem; font-weight: 500; }
        .viewer-box { background: #1e293b; border-radius: 8px; padding: 30px; border: 1px solid #334155; text-align: center; }
        .btn { background: #2563eb; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-size: 1rem; display: inline-block; border: none; cursor: pointer; font-weight: 600; transition: background 0.2s; }
        .btn:hover { background: #1d4ed8; }
        .btn-success { background: #059669; }
        .btn-success:hover { background: #047857; }
        .btn-secondary { background: #475569; }
        .btn-secondary:hover { background: #334155; }
        code { background: #0f172a; padding: 8px 12px; border-radius: 4px; color: #38bdf8; display: inline-block; font-family: monospace; user-select: all; margin-top: 5px; }
        #status-msg { margin-top: 15px; font-size: 0.9rem; color: #38bdf8; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>診療画像カスタムビューワ (フラグ制御)</h1>
            <a href="index.php" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.85rem;">◀ 一覧に戻る</a>
        </header>

        <?php if ($record): ?>
            <div class="info-card">
                <div class="info-item"><label>患者ID</label><div><?= htmlspecialchars($record['id']) ?></div></div>
                <div class="info-item"><label>患者氏名</label><div><?= htmlspecialchars($record['kanji']) ?></div></div>
                <div class="info-item"><label>検査名</label><div><?= htmlspecialchars($record['kensamei']) ?></div></div>
                <div class="info-item"><label>検査日</label><div><?= htmlspecialchars($record['date']) ?></div></div>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>画像格納フォルダパス (UNC)</label>
                    <div><code><?= htmlspecialchars($record['folder']) ?></code></div>
                </div>
            </div>

            <div class="viewer-box">
                <h3 style="margin-top: 0; color: #e2e8f0;">RSView.exe 起動コントロール</h3>
                <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.6; margin-bottom: 25px;">
                    ボタンをクリックするとサーバー上のフラグが立ち、<br>
                    常駐システムが検知して <code>RSView.exe</code> を直接起動します。
                </p>
                
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; align-items: center;">
                    <?php if (!empty($record['folder'])): ?>
                        <button class="btn btn-success" onclick="launchRSView('<?= htmlspecialchars($record['folder'], ENT_QUOTES) ?>')">
                            🚀 RSView.exe で起動する
                        </button>
                    <?php endif; ?>
                    <button class="btn btn-secondary" style="font-size: 0.9rem; padding: 12px 18px;" onclick="copyPath('<?= htmlspecialchars($record['folder'], ENT_QUOTES) ?>')">
                        📋 パスをコピー
                    </button>
                </div>
                <div id="status-msg"></div>
            </div>
        <?php else: ?>
            <div class="viewer-box">
                <p style="color: #ef4444;">対象の患者データが見つかりませんでした。</p>
                <?php if($error_message): ?><p><?= htmlspecialchars($error_message) ?></p><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    function launchRSView(folderPath) {
        const statusMsg = document.getElementById('status-msg');
        statusMsg.textContent = "起動フラグを送信中...";
        
        const formData = new URLSearchParams();
        formData.append('action', 'launch');
        formData.append('folder', folderPath);

        fetch('viewer.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                statusMsg.textContent = "✅ 起動フラグを立てました。ビューワが立ち上がります。";
                setTimeout(() => { statusMsg.textContent = ""; }, 4000);
            } else {
                statusMsg.textContent = "❌ エラー: " + data.message;
            }
        })
        .catch(err => {
            statusMsg.textContent = "❌ 通信エラーが発生しました。";
        });
    }

    function copyPath(path) {
        navigator.clipboard.writeText(path).then(() => {
            alert("フォルダパスをクリップボードにコピーしました。");
        }).catch(err => {
            alert("コピーに失敗しました: " + path);
        });
    }
    </script>
</body>
</html>