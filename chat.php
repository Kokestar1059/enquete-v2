<?php

/**
 * chat.php  ── Azure OpenAI への「中継サーバー」
 *
 * 役割:
 *   index.php の JS が fetch で送ってきた3つの回答を受け取り、
 *   Azure OpenAI（v1 API）に「5カテゴリのどれか」を判定させて、
 *   結果を JSON（{"category":"インフラ"} 等）で返す。
 *
 * なぜ中継するのか（重要）:
 *   APIキーをブラウザ側に出さないため。ブラウザのJSは自前のこのchat.phpだけを呼び、
 *   キーを使う通信はサーバー側（このPHP）で行う。
 *
 * 学習メモ（Supabase経験との対応）:
 *   php://input        … fetch が送ってきた JSON 本文を生で読む（≒ request body）
 *   json_decode        … JSON文字列 → PHPの配列に変換
 *   curl_*             … PHPから外部API（Azure）へHTTPリクエストを送る道具
 *   json_encode        … PHPの配列 → JSON文字列（ブラウザに返す）
 */

require_once "config.php";
require_once "db.php";
require_once "rate_limit.php";

// このファイルの応答は「JSON」だとブラウザに伝える（charsetも明示）
header("Content-Type: application/json; charset=utf-8");

// 分類に使う5カテゴリ（ここが唯一の正解集合。AIの返事もこの中に矯正する）
$CATEGORIES = ["インフラ", "自然環境", "安全", "飲食・施設", "その他"];

/**
 * エラー時に JSON を返して終了する小さなヘルパー。
 * 画面ではなくJSで受け取るので、人間向けHTMLではなくJSONで返す。
 */
function respond_error($message, $httpStatus = 400)
{
    http_response_code($httpStatus);
    echo json_encode(["error" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) POST 以外は受け付けない（中継APIなので画面表示はしない）
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond_error("POSTで呼んでください。", 405);
}

// 1.5) レート制限（#6）: Azureに通す前に連打・大量呼び出しを弾く門番。
//      chat.php は分類1語(max_tokens 20)と軽いので analyze より緩め＝5秒に1回・1日200回まで。
//      action="chat" で analyze とはカウンタを分けるので、互いに干渉しない。
rate_limit_guard(db(), "chat", 5, 200);

// 2) fetch が送ってきた JSON 本文を読み取る
$raw = file_get_contents("php://input");
$in  = json_decode($raw, true); // true = 連想配列で受け取る

if (!is_array($in)) {
    respond_error("リクエスト本文がJSONではありません。");
}

// 3) 3つの回答を取り出す（無ければ空文字）
$frequency = trim($in["frequency"] ?? "");
$purpose   = trim($in["purpose"]   ?? "");
$complaint = trim($in["complaint"] ?? "");

if ($frequency === "" && $purpose === "" && $complaint === "") {
    respond_error("回答が空です。");
}

// 4) AIへの指示文を組み立てる
//    system: 役割と「5カテゴリの中から1語だけ返す」ルールを与える
//    user  : 実際の3回答を渡す
$systemPrompt =
    "あなたは鵠沼海岸まちづくりアンケートの分類担当です。" .
    "利用者の回答を、次の5カテゴリのいずれか1つに分類してください。\n" .
    "- インフラ（駐車場・トイレ・道路・アクセス）\n" .
    "- 自然環境（海・砂浜・景観・清潔さ）\n" .
    "- 安全（波・人混み・子供の安全）\n" .
    "- 飲食・施設（カフェ・シャワー・売店）\n" .
    "- その他\n" .
    "出力はカテゴリ名だけを返してください（例: インフラ）。" .
    "カッコ書きの説明・記号・文章は一切付けないこと。";

$userPrompt =
    "頻度: {$frequency}\n" .
    "目的: {$purpose}\n" .
    "不満・改善要望: {$complaint}";

// 5) Azure OpenAI（v1 API）に投げる本文を作る
//    v1 API では URL に api-version は不要。"model" にデプロイ名を入れる。
$url = rtrim(AZURE_OPENAI_ENDPOINT, "/") . "/openai/v1/chat/completions";

$payload = json_encode([
    "model" => AZURE_OPENAI_DEPLOYMENT,
    "messages" => [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user",   "content" => $userPrompt],
    ],
    "temperature" => 0,   // ブレを抑えて毎回同じ判定にしやすくする
    "max_tokens"  => 20,  // 返すのはカテゴリ名1語なので少しでよい
], JSON_UNESCAPED_UNICODE);

// 6) cURL で実際に送信する
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json",
        "api-key: " . AZURE_OPENAI_API_KEY, // ← キーはここ（サーバー側）だけで使う
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true, // 結果を文字列で受け取る
    CURLOPT_TIMEOUT        => 30,
]);

$res      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// 7) 通信そのものが失敗した場合
if ($res === false) {
    respond_error("AIへの通信に失敗しました: " . $curlErr, 502);
}

// 8) Azure 側がエラーを返した場合（401: キー違い / 404: デプロイ名違い 等）
if ($httpCode < 200 || $httpCode >= 300) {
    respond_error("AIがエラーを返しました（HTTP {$httpCode}）。", 502);
}

// 9) 応答JSONから本文（カテゴリ名）を取り出す
$data    = json_decode($res, true);
$content = $data["choices"][0]["message"]["content"] ?? "";
$content = trim($content);

// 10) AIの返事を「必ず5カテゴリのどれか」に矯正する。
//     AIは時々「インフラ（駐車場・…）」のようにカッコ説明ごと返すので、頑丈に判定する:
//       (a) 最初の「（」「(」より前だけを取り出して完全一致を試す
//       (b) それでも駄目なら、返事の中にカテゴリ名が含まれていないか探す
//       (c) どれにも当てはまらなければ「その他」に寄せる
$category = "その他"; // 既定値

$head = preg_split('/[（(]/u', $content, 2)[0]; // 「（」より前だけ
$head = trim($head);

if (in_array($head, $CATEGORIES, true)) {
    $category = $head;                       // (a) 一致
} else {
    foreach ($CATEGORIES as $cat) {          // (b) 含まれているカテゴリ名を探す
        if ($cat !== "その他" && mb_strpos($content, $cat) !== false) {
            $category = $cat;
            break;
        }
    }
}

// 11) ブラウザ（JS）へ JSON で返す
echo json_encode(["category" => $category], JSON_UNESCAPED_UNICODE);
