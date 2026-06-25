<?php

/**
 * insert.php
 * フォームから送られた回答を受け取り、responses テーブルに1行 INSERT する。
 *
 * 旧 write.php（CSV追記）からの変更点:
 *   - fopen/fwrite/fclose（CSV追記）→ PDO の prepare/execute（DBへINSERT）
 *   - clean_for_csv（カンマ・改行の置換）は廃止
 *       … DBは列が分かれているので、カンマや改行が混ざっても列ずれしない。
 *
 * 学習メモ:
 *   $_POST["name"]        … fetch で送られてきた値の受け取り（≒ supabase の request body）
 *   $pdo->prepare(SQL)    … SQLの「ひな形」を用意（値は ? のままにしておく）
 *   $stmt->execute([...]) … ? に値を当てはめて実行（≒ insert）。
 *                           値を直接SQLに連結しないので SQLインジェクション対策になる。
 */

require_once "functions.php";
require_once "db.php";

// POST 以外（ブラウザで insert.php を直接開いた等）のときは保存処理ができない。
// 入力フォームは index.php に一本化しているので、そちらへリダイレクトする。
//   header("Location: ...") … ブラウザに「このURLへ移動して」と指示する（≒ 画面遷移）
//   ※ header() より前に画面出力（echo やHTML）があると効かないので注意。
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

// ---- ここから POST されたときの処理 ----

// 1) $_POST から値を受け取る（未送信のときは空文字にしておく）
//    category は index.php の AI 分類結果。直接フォーム送信などで無いときは「未分類」にする。
$frequency = $_POST["frequency"] ?? "";
$purpose   = $_POST["purpose"]   ?? "";
$complaint = $_POST["complaint"] ?? "";
$category  = $_POST["category"]  ?? "未分類";

// 2) DBへ INSERT する。
//    created_at は書かない … responses テーブルの DEFAULT CURRENT_TIMESTAMP に任せる
//    （日時の管理元をDB1か所にして二重管理を避ける）。
$pdo = db();
$sql = "INSERT INTO responses (frequency, purpose, complaint, category)
        VALUES (?, ?, ?, ?)";
$stmt = $pdo->prepare($sql);
$stmt->execute([$frequency, $purpose, $complaint, $category]);

// 3) 完了画面に出す「回答日時」は、表示用に PHP の現在時刻を使う。
//    （DBの created_at の実値とはほぼ同時刻。厳密に一致させたい場合は
//      lastInsertId() で当該行を SELECT し直すが、ここでは表示用で十分。）
$datetime = date("Y-m-d H:i:s");

// 4) 保存完了メッセージ（表示は必ず h() を通す＝XSS対策）
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>保存しました</title>
</head>
<body>
    <h1>保存しました</h1>
    <ul>
        <li>回答日時：<?php echo h($datetime); ?></li>
        <li>頻度：<?php echo h($frequency); ?></li>
        <li>目的：<?php echo h($purpose); ?></li>
        <li>不満内容：<?php echo h($complaint); ?></li>
        <li>分類カテゴリ：<?php echo h($category); ?></li>
    </ul>
    <p><a href="select.php">一覧を見る（select.php / #3で作成予定）</a></p>
    <p><a href="index.php">もう一度入力する</a></p>
</body>
</html>
