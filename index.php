<?php

/**
 * index.php
 * 鵠沼海岸まちづくりアンケートの「AI会話風」入力画面。
 *
 * 流れ（#5）:
 *   JSが3問を1問ずつ提示して回答を集める
 *     → fetch で chat.php に JSON 送信（AIが5カテゴリに分類）
 *     → 分類結果を表示
 *     → 「保存する」で insert.php に通常フォームPOST（categoryも一緒に）
 *     → select.php で一覧表示
 *
 * メモ:
 *   - APIキーはサーバー側 chat.php だけで使う。ここ（ブラウザのJS）には出さない。
 *   - 利用者の入力は textContent で差し込む（innerHTML を避けてXSS対策）。
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>鵠沼海岸まちづくりアンケート</title>
    <style>
        body { font-family: sans-serif; max-width: 640px; margin: 24px auto; padding: 0 16px; line-height: 1.6; }
        h1 { font-size: 1.4rem; }
        #log { border: 1px solid #ccc; border-radius: 8px; padding: 12px; min-height: 160px; }
        .bubble { margin: 8px 0; padding: 8px 12px; border-radius: 12px; max-width: 80%; }
        .ai   { background: #eef3ff; }
        .me   { background: #e8f7e8; margin-left: auto; text-align: right; }
        .area { margin-top: 12px; display: flex; gap: 8px; }
        .area input[type=text] { flex: 1; padding: 8px; font-size: 1rem; }
        button { padding: 8px 16px; font-size: 1rem; cursor: pointer; }
        #result { margin-top: 16px; padding: 12px; border: 1px solid #8ab; border-radius: 8px; background: #f4fbff; display: none; }
        .note { color: #666; font-size: 0.9rem; }
    </style>
</head>
<body>
    <h1>鵠沼海岸まちづくりアンケート</h1>
    <p class="note">AIが3つの質問をします。順番にお答えください。</p>

    <!-- 会話の表示エリア（JSが吹き出しを追加していく） -->
    <div id="log"></div>

    <!-- 入力エリア（回答中だけ表示） -->
    <div class="area" id="inputArea">
        <input type="text" id="answer" placeholder="ここに入力" autocomplete="off">
        <button type="button" id="nextBtn">次へ</button>
    </div>

    <!-- 分類結果＆保存エリア（3問終了後に表示） -->
    <div id="result">
        <p>あなたの回答は <strong id="categoryText"></strong> に分類されました。</p>
        <!-- 保存は insert.php への通常フォームPOST。値はJSが下のhiddenに入れる -->
        <form method="post" action="insert.php">
            <input type="hidden" name="frequency" id="f_frequency">
            <input type="hidden" name="purpose"   id="f_purpose">
            <input type="hidden" name="complaint" id="f_complaint">
            <input type="hidden" name="category"  id="f_category">
            <button type="submit">保存する</button>
        </form>
    </div>

    <p><a href="read.php">回答一覧を見る（read.php）</a></p>

    <script>
        // 固定の3問。keyは chat.php / insert.php が受け取る name と揃える。
        const QUESTIONS = [
            { key: "frequency", text: "鵠沼海岸にどのくらいの頻度で来ますか？" },
            { key: "purpose",   text: "主にどんな目的で来ますか？" },
            { key: "complaint", text: "不満や改善してほしいことはありますか？" },
        ];

        const answers = {};   // 集めた回答 { frequency:..., purpose:..., complaint:... }
        let step = 0;         // 今どの質問か

        const log       = document.getElementById("log");
        const inputArea = document.getElementById("inputArea");
        const answerEl  = document.getElementById("answer");
        const nextBtn   = document.getElementById("nextBtn");
        const result    = document.getElementById("result");

        // 吹き出しを1つ追加する。textContent を使うので入力に<script>が混ざっても安全。
        function addBubble(text, who) {
            const div = document.createElement("div");
            div.className = "bubble " + who;
            div.textContent = text;
            log.appendChild(div);
            log.scrollTop = log.scrollHeight;
        }

        // 今の質問をAIの吹き出しとして出す
        function askCurrent() {
            addBubble(QUESTIONS[step].text, "ai");
            answerEl.value = "";
            answerEl.focus();
        }

        // 「次へ」：回答を記録して次の質問へ。3問そろったら分類へ。
        function onNext() {
            const val = answerEl.value.trim();
            if (val === "") {
                answerEl.focus();
                return; // 空のままは進めない
            }
            addBubble(val, "me");                 // 自分の回答を表示
            answers[QUESTIONS[step].key] = val;   // 記録
            answerEl.value = "";                  // 次の質問に備えて即クリア
            step++;

            if (step < QUESTIONS.length) {
                askCurrent();                     // 次の質問へ
            } else {
                inputArea.style.display = "none"; // 入力欄を隠す
                classify();                       // AI分類へ
            }
        }

        // chat.php に回答を送り、カテゴリを受け取る
        async function classify() {
            addBubble("回答を分類しています…", "ai");
            try {
                const res = await fetch("chat.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(answers),
                });
                const data = await res.json();

                if (!res.ok || data.error) {
                    addBubble("分類でエラーが発生しました: " + (data.error || res.status), "ai");
                    return;
                }

                // 分類結果を表示し、保存フォームのhiddenに値を詰める
                addBubble("分類できました：" + data.category, "ai");
                document.getElementById("categoryText").textContent = data.category;
                document.getElementById("f_frequency").value = answers.frequency;
                document.getElementById("f_purpose").value   = answers.purpose;
                document.getElementById("f_complaint").value = answers.complaint;
                document.getElementById("f_category").value  = data.category;
                result.style.display = "block";
            } catch (e) {
                addBubble("通信に失敗しました。もう一度お試しください。", "ai");
            }
        }

        // ボタンとEnterキーで「次へ」
        nextBtn.addEventListener("click", onNext);
        answerEl.addEventListener("keydown", (e) => {
            // 日本語入力(IME)の「変換確定」Enterはここで弾く。
            //   e.isComposing      … IMEで変換中なら true（最近のブラウザ）
            //   e.keyCode === 229  … 同じく変換中を示す古い合図（保険）
            // → 変換確定の1回目は無視され、もう一度Enterで送信される。
            if (e.key === "Enter" && !e.isComposing && e.keyCode !== 229) {
                e.preventDefault();
                onNext();
            }
        });

        // 最初の質問を表示してスタート
        askCurrent();
    </script>
</body>
</html>
