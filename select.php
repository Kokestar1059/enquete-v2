<?php

/**
 * select.php
 * responses テーブルを SELECT して、回答一覧を表で表示する。
 * さらに、カテゴリ別の集計（件数・割合）を棒グラフで視覚化し、
 * 「AIで分析する」ボタンで analyze.php を呼んで分析文を表示する。
 *
 * 旧 read.php（CSV読取）からの変更点:
 *   - fopen/fgets/explode（CSVを1行ずつ読む）→ PDO の SELECT に置換
 *   - 列数チェック（壊れた行スキップ）は不要 … DBは列が分かれているので列ずれしない
 *   - カテゴリ集計は SELECT で取った全行を PHP 側で数える（旧ロジックを流用）
 *
 * #7（カテゴリ正規化）での変更点:
 *   - カテゴリは responses.category_id（数値）→ categories マスタへ JOIN して名前を取得
 *   - 集計は PHP で数えるのをやめ、SQL の GROUP BY + COUNT に置き換え（DBに集計させる）
 *
 * 学習メモ:
 *   JOIN              … responses と categories を category_id = id で結合して名前を引く
 *   GROUP BY + COUNT  … カテゴリごとに件数をDB側で集計（≒ supabaseの集計）
 *   $pdo->query(SQL)  … 値を埋め込まない固定SQLを実行（≒ supabase の select）
 *   $stmt->fetchAll() … 全行を配列で受け取る（1行 = ["category"=>..., ...] の連想配列）
 *   ORDER BY ... DESC … 新しい回答が上に来るよう created_at の降順で並べる
 *   h(...)            … 画面出力前にエスケープ（XSS対策）
 */

require_once "functions.php";
require_once "db.php";

// 表の列見出し（表示用）。DBの列順に合わせて並べる。
$headers = ["回答日時", "頻度", "目的", "不満内容", "分類カテゴリ"];

// 集計対象のカテゴリ（この順で棒グラフに並べる）。これ以外は「その他」に寄せる。
$CATEGORIES = ["インフラ", "自然環境", "安全", "飲食・施設", "その他"];

// カテゴリ別の件数を0で初期化（連想配列）
$counts = [];
foreach ($CATEGORIES as $cat) {
    $counts[$cat] = 0;
}

$pdo = db();

// カテゴリ別の件数を SQL の GROUP BY で集計する。#7
//   responses を categories に JOIN し、カテゴリ名ごとに件数(COUNT)を出す。
//   集計をPHPで数えるのではなくDBに任せる（リレーショナルDBの基本）。
$aggSql = "SELECT c.name AS name, COUNT(r.id) AS cnt
           FROM responses r
           JOIN categories c ON c.id = r.category_id
           GROUP BY c.id, c.name";
foreach ($pdo->query($aggSql) as $agg) {
    $name = $agg["name"];
    $n    = (int)$agg["cnt"];
    // 5カテゴリのどれかなら加算、それ以外（「未分類」など）は「その他」に寄せる。
    if (isset($counts[$name])) {
        $counts[$name] += $n;
    } else {
        $counts["その他"] += $n;
    }
}

// 一覧表示用に responses を新しい順に全件 SELECT する。
//   カテゴリ名は category_id から JOIN で引く（c.name を「category」という名前で受ける）。
$listSql = "SELECT r.created_at, r.frequency, r.purpose, r.complaint, c.name AS category
            FROM responses r
            JOIN categories c ON c.id = r.category_id
            ORDER BY r.created_at DESC";
$rows = $pdo->query($listSql)->fetchAll();

// 集計の合計と最大件数（棒の長さの基準に使う）
$total    = count($rows);
$maxCount = ($total > 0) ? max($counts) : 0;

// 保存済みの分析（最新1行）を読む。
//   履歴方式なので、最新を created_at の降順で1件だけ取る（API は呼ばない＝課金しない）。
//   まだ1件も無ければ $latestAnalysis は false になる。
$latestAnalysis = $pdo
    ->query("SELECT content, created_at FROM analysis ORDER BY created_at DESC LIMIT 1")
    ->fetch();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>回答一覧（select.php）</title>
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

        <!-- ▼ AIによる分析（DBに保存済みの最新分析を表示。API は呼ばない） -->
        <h2>AIによる分析</h2>
        <p class="muted">DBに保存された最新の分析を表示します。</p>
        <!-- 分析が既にあれば「再分析する」、無ければ「AIで分析する」と出し分ける -->
        <button type="button" id="analyzeBtn"><?php echo $latestAnalysis ? "再分析する" : "AIで分析する"; ?></button>

        <!-- 最終分析日時。再分析後に JS で中身を書き換えるので、分析が無いときは隠しておく -->
        <p class="muted" id="analyzedAt" style="<?php echo $latestAnalysis ? "" : "display: none;"; ?>">
            最終分析日時: <span id="analyzedAtValue"><?php echo $latestAnalysis ? h($latestAnalysis["created_at"]) : ""; ?></span>
        </p>

        <?php if ($latestAnalysis): ?>
            <div id="analysis" style="display: block;"><?php echo h($latestAnalysis["content"]); ?></div>
        <?php else: ?>
            <p class="muted" id="noAnalysis">まだ分析がありません。「AIで分析する」を押すと分析を作成します。</p>
            <div id="analysis"></div>
        <?php endif; ?>
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
                let original = btn.textContent;
                btn.textContent = "分析中…";

                // 「まだ分析がありません」の案内が出ていれば消す（分析を表示するので不要になる）
                const noAnalysis = document.getElementById("noAnalysis");
                if (noAnalysis) {
                    noAnalysis.remove();
                }

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

                        // 最終分析日時を更新（リロードせずに画面へ反映）
                        const analyzedAt      = document.getElementById("analyzedAt");
                        const analyzedAtValue = document.getElementById("analyzedAtValue");
                        if (analyzedAtValue && data.created_at) {
                            analyzedAtValue.textContent = data.created_at;
                        }
                        if (analyzedAt) {
                            analyzedAt.style.display = "";
                        }

                        // 一度分析したら以降は「再分析」の意図に揃える
                        original = "再分析する";
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
