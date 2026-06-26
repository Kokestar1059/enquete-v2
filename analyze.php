<?php

/**
 * analyze.php  ── 回答全体を AI に分析させる「中継サーバー」
 *
 * 役割:
 *   select.php の「再分析」ボタンから fetch(POST) で呼ばれる。
 *   サーバー側で responses テーブルを SELECT し、カテゴリ集計＋不満内容を
 *   Azure OpenAI(v1 API) に渡して、傾向・要約・改善提案の文章を生成する。
 *   生成した分析文は analysis テーブルに1行 INSERT して保存し、JSONでも返す。
 *
 * ポイント:
 *   - APIキーはサーバー側のここだけで使う（ブラウザに出さない）。
 *   - ボタンを押したときだけ呼ばれる設計（毎回の自動実行はコスト/待ちが出るため）。
 *   - データ取得は CSV ではなく responses テーブルから（#2/#3 でDB化済み）。
 *   - 分析方式は「履歴を持つ」… 毎回 INSERT で追加する（上書きしない）。
 */

require_once "config.php";
require_once "db.php";

header("Content-Type: application/json; charset=utf-8");

function respond_error($message, $httpStatus = 400)
{
    http_response_code($httpStatus);
    echo json_encode(["error" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// 自動実行を避けるため POST のときだけ動かす（ボタンの fetch は POST）
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond_error("POSTで呼んでください。", 405);
}

// 0) レート制限（#6）: Azureに通す前に連打・大量呼び出しを弾く門番。
//    analyze.php はログイン不要の公開エンドポイントなので、外部から連打されると
//    Azure使用量が爆発しうる。同IPの呼び出し回数を rate_limits テーブルで数えて制限する。
$pdo = db();
$ip  = $_SERVER["REMOTE_ADDR"] ?? "unknown"; // 呼び出し元IP（取れないときは "unknown"）

// 古い記録（24時間より前）を掃除しておく（テーブルが無限に太らないように）。
$pdo->prepare("DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)")->execute();

// (a) 連打制限: 直近10秒に同IPの呼び出しがあれば 429 で弾く（10秒に1回まで）。
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM rate_limits
     WHERE ip = ? AND created_at > (NOW() - INTERVAL 10 SECOND)"
);
$stmt->execute([$ip]);
if ((int)$stmt->fetchColumn() > 0) {
    respond_error("短時間に何度も実行されました。10秒ほど待ってから再度お試しください。", 429);
}

// (b) 1日の上限: 直近24時間に同IPが100回以上なら 429 で弾く（1日100回まで）。
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM rate_limits
     WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 DAY)"
);
$stmt->execute([$ip]);
if ((int)$stmt->fetchColumn() >= 100) {
    respond_error("本日の分析回数の上限に達しました。時間をおいて再度お試しください。", 429);
}

// 制限クリア。今回の呼び出しを記録してから処理を続ける。
//   Azure呼び出しの「前」に記録する … 通信失敗時に連続リトライされても回数に数えて抑止するため。
$pdo->prepare("INSERT INTO rate_limits (ip) VALUES (?)")->execute([$ip]);

// 1) responses テーブルから、カテゴリ件数と不満内容を集める
//    値を埋め込まない固定SQLなので query() でOK（プリペアドは外部入力を渡すときに使う）。
$counts     = []; // カテゴリ名 => 件数
$complaints = []; // 不満内容の一覧
$total      = 0;

// カテゴリ別件数は JOIN + GROUP BY でDBに集計させる（#7：正規化に合わせて変更）。
$aggSql = "SELECT c.name AS name, COUNT(r.id) AS cnt
           FROM responses r
           JOIN categories c ON c.id = r.category_id
           GROUP BY c.id, c.name";
foreach ($pdo->query($aggSql) as $agg) {
    $counts[$agg["name"]] = (int)$agg["cnt"];
    $total += (int)$agg["cnt"];
}

// 不満内容（自由回答）は空でないものだけ集める。
foreach ($pdo->query("SELECT complaint FROM responses") as $row) {
    $complaint = $row["complaint"] ?? "";
    if ($complaint !== "") {
        $complaints[] = $complaint;
    }
}

if ($total === 0) {
    respond_error("分析できる回答がまだありません。");
}

// 2) AI に渡す材料を文字列にまとめる
//    集計（カテゴリ: 件数）
$countLines = "";
foreach ($counts as $cat => $n) {
    $countLines .= "- {$cat}: {$n}件\n";
}
//    不満内容の一覧（多すぎると長いので上限を設ける）
$maxComplaints  = 50;
$complaintLines = "";
foreach (array_slice($complaints, 0, $maxComplaints) as $c) {
    $complaintLines .= "- {$c}\n";
}

// 3) 指示文を組み立てる
$systemPrompt =
    "あなたは鵠沼海岸まちづくりアンケートの分析担当です。" .
    "与えられた集計と自由回答をもとに、傾向を簡潔な日本語でまとめてください。" .
    "次の3点を、それぞれ短い箇条書きで示してください。\n" .
    "1. 全体の傾向\n" .
    "2. カテゴリ別の要点\n" .
    "3. 改善のヒント\n" .
    "推測しすぎず、データに沿って書くこと。";

$userPrompt =
    "■回答総数: {$total}件\n\n" .
    "■カテゴリ別件数:\n{$countLines}\n" .
    "■不満・改善要望（自由回答・一部）:\n{$complaintLines}";

// 4) Azure OpenAI(v1 API) を cURL で呼ぶ
$url = rtrim(AZURE_OPENAI_ENDPOINT, "/") . "/openai/v1/chat/completions";

$payload = json_encode([
    "model" => AZURE_OPENAI_DEPLOYMENT,
    "messages" => [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user",   "content" => $userPrompt],
    ],
    "temperature" => 0.3,
    "max_tokens"  => 600,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json",
        "api-key: " . AZURE_OPENAI_API_KEY,
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
]);

$res      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($res === false) {
    respond_error("AIへの通信に失敗しました: " . $curlErr, 502);
}
if ($httpCode < 200 || $httpCode >= 300) {
    respond_error("AIがエラーを返しました（HTTP {$httpCode}）。", 502);
}

// 5) 応答から分析文を取り出して返す
$data     = json_decode($res, true);
$analysis = trim($data["choices"][0]["message"]["content"] ?? "");

if ($analysis === "") {
    respond_error("分析結果を取得できませんでした。", 502);
}

// 6) 分析文を analysis テーブルに保存（履歴方式: 毎回1行 INSERT）
//    値は必ずプリペアドで渡す（SQLインジェクション対策）。
$stmt = $pdo->prepare("INSERT INTO analysis (content) VALUES (?)");
$stmt->execute([$analysis]);

// 保存した行の created_at を読み戻す（DB側で自動セットされた値）。
//   画面の「最終分析日時」を、リロードせずに更新するために返す。
$insertedId = $pdo->lastInsertId();
$stmt       = $pdo->prepare("SELECT created_at FROM analysis WHERE id = ?");
$stmt->execute([$insertedId]);
$createdAt  = $stmt->fetchColumn();

echo json_encode(
    ["analysis" => $analysis, "created_at" => $createdAt],
    JSON_UNESCAPED_UNICODE
);
