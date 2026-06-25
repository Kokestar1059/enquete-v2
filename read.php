<?php

/**
 * read.php
 * data/data.csv を読み取り、回答一覧を表で表示する。
 * さらに、カテゴリ別の集計（件数・割合）を棒グラフで視覚化し、
 * 「AIで分析する」ボタンで analyze.php を呼んで分析文を表示する。
 *
 * CSVの列順（write.php と必ず揃える）:
 *   回答日時, 頻度, 目的, 不満内容, 分類カテゴリ
 *
 * 学習メモ:
 *   fopen(..., "r")   … 読み取りモードで開く
 *   feof($f)          … ファイルの終わり(End Of File)に来たら true
 *   fgets($f)         … 1行ずつ取り出す（≒ select で1件ずつ読む感じ）
 *   explode(",", ...) … カンマ区切りの文字列を配列に分解する
 *   連想配列で件数を数える … $counts[$cat]++ がカテゴリ別集計の定番
 *   h(...)            … 画面出力前にエスケープ（XSS対策）
 */

require_once "functions.php";

// 列見出し（表示用）。CSVにはヘッダ行を保存していないので、ここで用意する。
$headers = ["回答日時", "頻度", "目的", "不満内容", "分類カテゴリ"];
$columns = count($headers); // = 5

// 集計対象のカテゴリ（この順で棒グラフに並べる）。これ以外は「その他」に寄せる。
$CATEGORIES = ["インフラ", "自然環境", "安全", "飲食・施設", "その他"];

// 読み取った行をためる配列
$rows = [];

// カテゴリ別の件数を0で初期化（連想配列）
$counts = [];
foreach ($CATEGORIES as $cat) {
    $counts[$cat] = 0;
}

$f = fopen("data/data.csv", "r");
if ($f !== false) {
    while (!feof($f)) {
        $line = fgets($f);

        // fgets はファイル末尾で false を返すことがあるので、文字列のときだけ処理
        if ($line === false) {
            break;
        }

        // 前後の空白・改行を除去。空行はスキップ（空ファイル・末尾の空行対策）
        $line = trim($line);
        if ($line === "") {
            continue;
        }

        // カンマで分割
        $cells = explode(",", $line);

        // 列数が想定（5列）と違う壊れた行はスキップする
        if (count($cells) !== $columns) {
            continue;
        }

        $rows[] = $cells;

        // 5列目（添字4）が分類カテゴリ。5カテゴリのどれかなら加算、
        // それ以外（古い「未分類」など）は「その他」に寄せて数える。
        $category = $cells[4];
        if (isset($counts[$category])) {
            $counts[$category]++;
        } else {
            $counts["その他"]++;
        }
    }
    fclose($f);
}

// 集計の合計と最大件数（棒の長さの基準に使う）
$total    = count($rows);
$maxCount = ($total > 0) ? max($counts) : 0;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>回答一覧（read.php）</title>
    <style>
        body { font-family: sans-serif; max-width: 720px; margin: 24px auto; padding: 0 16px; line-height: 1.6; }
        h1 { font-size: 1.4rem; }
        h2 { font-size: 1.1rem; margin-top: 28px; }
        table { border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; }
        /* 棒グラフ */
        .bar-row { display: flex; align-items: center; gap: 8px; margin: 6px 0; }
        .bar-label { width: 6.5em; text-align: right; flex: none; }
        .bar-track { flex: 1; background: #eee; border-radius: 4px; overflow: hidden; height: 18px; }
        .bar-fill { display: block; height: 18px; background: #4a90d9; border-radius: 4px; min-width: 2px; }
        .bar-num { width: 7em; flex: none; color: #444; font-size: 0.9rem; }
        /* AI分析 */
        #analysis { margin-top: 12px; padding: 12px; border: 1px solid #8ab; border-radius: 8px;
                    background: #f4fbff; white-space: pre-wrap; display: none; }
        .muted { color: #666; font-size: 0.9rem; }
        button { padding: 8px 16px; font-size: 1rem; cursor: pointer; }
    </style>
</head>
<body>
    <h1>回答一覧</h1>

    <!-- ▼ カテゴリ別の集計（棒グラフで視覚化） -->
    <h2>カテゴリ別の集計（全 <?php echo h($total); ?> 件）</h2>

    <?php if ($total === 0): ?>
        <p>まだ集計するデータがありません。</p>
    <?php else: ?>
        <?php foreach ($CATEGORIES as $cat): ?>
            <?php
                $n       = $counts[$cat];
                $percent = ($total > 0) ? round($n / $total * 100) : 0;
                // 棒の長さは「最大件数を100%」として相対的に伸ばす（見やすさ重視）
                $width   = ($maxCount > 0) ? round($n / $maxCount * 100) : 0;
            ?>
            <div class="bar-row">
                <span class="bar-label"><?php echo h($cat); ?></span>
                <span class="bar-track">
                    <span class="bar-fill" style="width: <?php echo h($width); ?>%;"></span>
                </span>
                <span class="bar-num"><?php echo h($n); ?>件（<?php echo h($percent); ?>%）</span>
            </div>
        <?php endforeach; ?>

        <!-- ▼ AIによる分析（ボタンを押したときだけ実行） -->
        <h2>AIによる分析</h2>
        <p class="muted">回答全体の傾向をAIがまとめます（押したときだけ実行します）。</p>
        <button type="button" id="analyzeBtn">AIで分析する</button>
        <div id="analysis"></div>
    <?php endif; ?>

    <!-- ▼ 回答一覧の表 -->
    <h2>回答の一覧</h2>
    <?php if ($total === 0): ?>
        <p>まだ回答がありません。</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $head): ?>
                        <th><?php echo h($head); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td><?php echo h($cell); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p style="margin-top: 24px;"><a href="index.php">新しく回答する（index.php）</a></p>

    <script>
        // 「AIで分析する」ボタン：押したときだけ analyze.php を呼ぶ
        const btn      = document.getElementById("analyzeBtn");
        const analysis = document.getElementById("analysis");

        if (btn) {
            btn.addEventListener("click", async () => {
                btn.disabled = true;
                const original = btn.textContent;
                btn.textContent = "分析中…";
                analysis.style.display = "block";
                analysis.textContent = "AIが分析しています。しばらくお待ちください…";

                try {
                    const res  = await fetch("analyze.php", { method: "POST" });
                    const data = await res.json();

                    if (!res.ok || data.error) {
                        analysis.textContent = "分析でエラーが発生しました: " + (data.error || res.status);
                    } else {
                        // textContent なので回答に記号等が混ざっても安全（XSS対策）
                        analysis.textContent = data.analysis;
                    }
                } catch (e) {
                    analysis.textContent = "通信に失敗しました。もう一度お試しください。";
                } finally {
                    btn.disabled = false;
                    btn.textContent = original;
                }
            });
        }
    </script>
</body>
</html>
