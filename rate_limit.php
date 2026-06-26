<?php

/**
 * rate_limit.php
 * 公開エンドポイント（analyze.php / chat.php）の連打・大量呼び出しを防ぐ共通の門番。
 *
 * なぜ必要か:
 *   これらは「ログイン不要で誰でも POST できる」公開エンドポイントで、1回ごとに
 *   Azure へ1リクエスト走る。外部から連打（curl/スクリプト）されると使用量が
 *   爆発しうるので、Azure に通す「前」に同IPの呼び出し回数を数えて弾く。
 *
 * 仕組み（ログ方式）:
 *   呼び出すたびに rate_limits テーブルへ「action + ip + 時刻」を1行INSERTし、
 *   そのテーブルを数えるだけで「直近N秒」も「直近24時間」も判定できる。
 *   action（'analyze' / 'chat'）で分けるので、機能ごとに別カウンタになる。
 *
 * 学習メモ:
 *   require_once "rate_limit.php"; のあと、各エンドポイントの先頭で
 *   rate_limit_guard($pdo, "analyze", 10, 100); のように1行呼ぶだけ。
 *   超過していれば 429(JSON) を返してその場で exit する。
 */

/**
 * レート制限チェック。超過していれば 429(JSON) を返して即終了する。
 * 通過したら今回の呼び出しを rate_limits に記録して呼び出し元へ戻る。
 *
 * @param PDO    $pdo        DB接続
 * @param string $action     エンドポイント識別子（'analyze' / 'chat'）。カウンタを分ける
 * @param int    $perSeconds この秒数に1回まで（連打制限）
 * @param int    $dailyMax   直近24時間にこの回数まで（総量制限）
 */
function rate_limit_guard(PDO $pdo, string $action, int $perSeconds, int $dailyMax): void
{
    $ip = $_SERVER["REMOTE_ADDR"] ?? "unknown"; // 呼び出し元IP（取れないときは "unknown"）

    // 秒数はこのコード内の定数（外部入力ではない）が、念のため (int) で整数化して埋める。
    //   ＝ INTERVAL の単位部分は外部値を一切混ぜない＝SQLインジェクションの余地を残さない。
    $perSeconds = (int)$perSeconds;

    // 古い記録（24時間より前）を掃除しておく（テーブルが無限に太らないように）。
    $pdo->prepare("DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)")->execute();

    // (a) 連打制限: 直近 $perSeconds 秒に同 action・同IP の呼び出しがあれば 429。
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_limits
         WHERE action = ? AND ip = ? AND created_at > (NOW() - INTERVAL {$perSeconds} SECOND)"
    );
    $stmt->execute([$action, $ip]);
    if ((int)$stmt->fetchColumn() > 0) {
        rate_limit_reject("短時間に何度も実行されました。少し待ってから再度お試しください。");
    }

    // (b) 1日の上限: 直近24時間に同 action・同IP が $dailyMax 回以上なら 429。
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_limits
         WHERE action = ? AND ip = ? AND created_at > (NOW() - INTERVAL 1 DAY)"
    );
    $stmt->execute([$action, $ip]);
    if ((int)$stmt->fetchColumn() >= $dailyMax) {
        rate_limit_reject("本日の実行回数の上限に達しました。時間をおいて再度お試しください。");
    }

    // 通過。今回の呼び出しを記録してから処理を続ける。
    //   API呼び出しの「前」に記録する … 通信失敗時に連続リトライされても回数に数えて抑止するため。
    $stmt = $pdo->prepare("INSERT INTO rate_limits (action, ip) VALUES (?, ?)");
    $stmt->execute([$action, $ip]);
}

/**
 * レート制限に引っかかったとき 429 を JSON で返して終了する小さなヘルパー。
 * （analyze.php / chat.php どちらも応答はJSONなので共通化できる）
 */
function rate_limit_reject(string $message): void
{
    http_response_code(429);
    echo json_encode(["error" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
