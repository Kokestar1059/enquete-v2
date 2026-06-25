<?php

/**
 * db.php
 * データベース接続を1か所にまとめる共通ファイル。
 *
 * 使い方:
 *   require_once "db.php";
 *   $pdo = db();                       // ← Supabase クライアントを受け取るイメージ
 *   $stmt = $pdo->prepare("SELECT ..."); // 値は必ずプリペアドで渡す（SQLインジェクション対策）
 *   $stmt->execute([...]);
 *
 * 学習メモ:
 *   PDO ≈ Supabaseクライアント。INSERT ≈ insert、SELECT ≈ select。
 *   接続情報（ホスト/DB名/ユーザー/パス）は config.php に置く（Git管理外）。
 */

require_once __DIR__ . "/config.php";

/**
 * DB接続オブジェクト（PDO）を返す。
 * 一度作った接続は static で覚えておき、2回目以降は同じ接続を使い回す
 * （リクエストのたびに何度も繋ぎ直さないため）。
 */
function db(): PDO
{
    static $pdo = null;          // 接続を覚えておく入れ物（最初は未接続）
    if ($pdo !== null) {
        return $pdo;             // すでに繋いでいれば、それを返すだけ
    }

    // DSN = 接続先の文字列。host=localhost なら XAMPP のソケット経由で繋がる。
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    $options = [
        // SQLでエラーが起きたら例外を投げる（黙って失敗させず、すぐ気づけるように）
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        // 取得結果を「列名 => 値」の連想配列で受け取る（$row["category"] のように使える）
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // プリペアドをDB側で本物として扱う（インジェクション対策をより確実に）
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // 実際に接続する。失敗すれば例外が飛ぶ。
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
}
