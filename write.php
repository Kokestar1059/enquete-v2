<?php

/**
 * write.php
 * フォームから送られた回答を受け取り、data/data.csv に1行追記する。
 *
 * CSVの列順（read.php と必ず揃える）:
 *   回答日時, 頻度, 目的, 不満内容, 分類カテゴリ
 *
 * 学習メモ:
 *   $_POST["name"]      … fetch で送られてきた値の受け取り（≒ supabase でいう request body）
 *   fopen(..., "a")     … 追記モードで開く（"w" だと毎回上書きされて消えるので注意）
 *   fwrite($f, ...)     … 1行書き込み（≒ insert）
 *   fclose($f)          … 閉じる
 */

require_once "functions.php";

// POST 以外（ブラウザで write.php を直接開いた等）のときは保存処理ができない。
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

// 2) カンマ・改行対策（今回は fwrite + "," 連結方式なので、混ざると列がずれる）
//    半角カンマ → 全角「，」、改行 → スペース に置換して列ずれ・行ずれを防ぐ。
function clean_for_csv($s)
{
    $s = str_replace(",", "，", $s);              // 半角カンマを全角に
    $s = str_replace(["\r\n", "\r", "\n"], " ", $s); // 改行をスペースに
    return $s;
}

$frequency = clean_for_csv($frequency);
$purpose   = clean_for_csv($purpose);
$complaint = clean_for_csv($complaint);
$category  = clean_for_csv($category);

// 3) 回答日時を付与（分類カテゴリは上で受け取り済み）
$datetime = date("Y-m-d H:i:s");

// 4) CSV に1行追記する
$line = $datetime . "," . $frequency . "," . $purpose . "," . $complaint . "," . $category . "\n";

$f = fopen("data/data.csv", "a"); // "a" = 追記。前のデータは消えない
fwrite($f, $line);
fclose($f);

// 5) 保存完了メッセージ（表示は必ず h() を通す）
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
    <p><a href="read.php">一覧を見る（read.php / #3で作成予定）</a></p>
    <p><a href="write.php">もう一度入力する</a></p>
</body>
</html>
