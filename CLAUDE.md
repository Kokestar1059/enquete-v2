# CLAUDE.md

このファイルは Claude Code がこのプロジェクトで作業するたびに自動で読み込む常設の指示書です。
方針・規約・進め方の「要点」だけを書きます（肥大化させない）。

---

## 0. このプロジェクトの位置づけ（最初に読む）★
- **enquete-v2**。前課題 **enquete**（鵠沼海岸まちづくりアンケート）を土台にした**続きの課題**。
- enquete は提出済み・凍結（別フォルダ `htdocs/enquete/`・別リポジトリ・別URL）。**こちらは別物として切り出したコピー**。
- **流用できている資産（基本そのまま使う）**：
  - AI中継の考え方：`chat.php`（回答の5カテゴリ分類）/ `analyze.php`（全体分析）
  - 画面：`index.php`（会話風UI）/ `read.php`（一覧＋棒グラフ＋AI分析ボタン）
  - 共通：`functions.php`（h関数）/ `config.php`（APIキー。**同じAzureを使う**）
- **v2 の主目的（今回やること）**：
  1. **データ保存を CSV → データベース（MySQL）へ移行**する
  2. **アンケート結果も分析結果も DB に保存**する
  3. 分析は**毎回APIを呼ばず、DBに保存済みの結果を呼び出して表示**する
  4. **アンケートが1件増えるたびに再分析**して、保存済み分析を更新する
- UI・ファイル構成は、変えなくて済む所は**現状維持**。DB化に必要な所だけ手を入れる。

---

## 1. 元の仕様（インタビュー3問・分類）
3問固定インタビュー：
1. 鵠沼海岸にどのくらいの頻度で来ますか？
2. 主にどんな目的で来ますか？
3. 不満や改善してほしいことはありますか？

分類カテゴリ（5つ）：インフラ／自然環境／安全／飲食・施設／その他

---

## 2. 技術スタック
- **PHP**：フレームワークなし、素のPHP
- **AI API**：Azure OpenAI（v1 API・`api-version`不要）。`config.php` のキーを `chat.php` / `analyze.php` がサーバー側で使う中継方式（キーはブラウザに出さない）
- **データ保存**：★**MySQL**（v2の中心テーマ）。ローカルは XAMPP 同梱の **MariaDB**（MySQL完全互換。習ったMySQLの知識・SQL・phpMyAdmin・PDOの`mysql`ドライバがそのまま使える）、本番さくらは MySQL
- **ローカル環境**：XAMPP（Apache + PHP + MySQL/MariaDB）。`htdocs/enquete-v2/`、`http://localhost/enquete-v2/`
- **文字コード**：UTF-8（DB接続も `utf8mb4` で統一）

---

## 3. ファイル構成（v2の想定・実装時に相談して確定）
```
📁 enquete-v2/
   📄 index.php        ← 会話風UI（流用）
   📄 chat.php         ← 分類の中継（流用）
   📄 analyze.php      ← 全体分析の中継（DB保存に合わせて改修予定）
   📄 write.php        ← 保存：CSV追記 → DBへINSERTに変更
   📄 read.php         ← 一覧＋棒グラフ＋分析表示：CSV読取 → DB SELECTに変更
   📄 db.php           ← ★新規予定：DB接続（PDO）を共通化
   📄 functions.php    ← h()など（流用）
   📄 config.php       ← APIキー＋★DB接続情報。Git管理外
   📄 config.php.example← ひな形（DB項目も追記する）
   📁 data/            ← CSV時代の名残。DB移行後は不要になり得る
```

### DB設計の“たたき台”（実装前に一緒に確定する）
- `responses`：id / created_at / frequency / purpose / complaint / category
- `analysis`：id / created_at / content（最新の分析文。履歴を持つか1行上書きかは相談）
- 分析の更新タイミング：write.php で1件INSERTした直後に再分析→analysisを更新、を想定（ボタン押下の毎回API課金をなくす狙い）

> ※スキーマ・PDO/mysqliの選択・分析の「最新のみ/履歴」などは**勝手に決めず、実装前に提案して合意**する。

---

## 4. 秘密情報（APIキー・DBパスワード）の扱い ★重要
- `config.php` に **APIキー** と **DB接続情報（ホスト/DB名/ユーザー/パスワード）** を置く。**`.gitignore` でGit管理外**。
- リポジトリには `config.php.example`（ダミー値）だけ置く。
- 本番（さくら）へは `config.php` を **FTPで個別アップ**。DBはさくらの管理画面で作成し、その接続情報を本番 config.php に入れる。
- キーやパスワードを**コミット・ログ出力・コメントに書かない**。

---

## 5. コーディング規約
- **XSS対策**：画面出力は必ず `h()`（`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`）。
- **SQLは必ずプリペアドステートメント**（PDOの `prepare`/`execute`）で値を渡す＝**SQLインジェクション対策**。文字列連結でSQLを組まない。
- **DB接続は db.php に集約**し、各ファイルは `require` して使う。
- **シンプル優先**：余計な抽象化・ライブラリは入れない。素のPHP＋PDOを素直に使う。
- 文字コードUTF-8（DSNに `charset=utf8mb4`）。

### Kokiさんの理解の補助線（Supabase経験との対応）
- これまで：`$_POST`≈fetch受け取り / CSVの `fwrite`≈insert / `fgets`≈select。
- v2：**PDO ≈ Supabaseクライアント**、`INSERT` ≈ supabaseの insert、`SELECT` ≈ select。CSV関数がSQLに置き換わるイメージ。

### つまずき注意
1. ローカルは XAMPP の **MySQL(MariaDB) を起動**しておく（phpMyAdmin でDB/テーブル作成）。
2. 接続情報（ホスト/ユーザー/パス/DB名）はローカルとさくらで**異なる**。config.phpで切替。
3. 文字化け対策：接続charsetを `utf8mb4` に。テーブルも `utf8mb4`。
4. 自由記述のカンマ・改行は、DBなら**列ずれの心配なし**（CSV時代の `clean_for_csv` は不要になる）。

---

## 6. 開発の進め方（issue駆動）
- 機能ごとに **GitHub issue** を作る：**目的 / やること / 完了条件 / 参考・メモ**。
- 機能ごとに **feature ブランチ**（`feature/NN-名前`）→ 完了で **PR→main マージ**。
- 1 issue = 1つの小さな機能。小さく刻む。
- v2の最初の刻み（案）：①DB接続(db.php)＋テーブル作成 → ②write.phpをINSERT化 → ③read.phpをSELECT化 → ④分析結果のDB保存＆呼び出し → ⑤回答追加時の再分析。

---

## 7. ローカル実行・テスト（XAMPP）
- XAMPP の **Apache と MySQL(MariaDB) を起動**。`http://localhost/enquete-v2/` で確認。
- phpMyAdmin（`http://localhost/phpmyadmin/`）でDB作成・中身確認。
- CLI簡易確認：`/Applications/XAMPP/xamppfiles/bin/php -l ファイル名`（構文チェック）。
- 変更したら必ずブラウザで動かして確認する。

---

## 8. デプロイ（さくらサーバー）
- enquete とは**別フォルダ**：`www/enquete-v2/` → `https://dev-gs-kokes.sakura.ne.jp/enquete-v2/`（enquete はそのまま残す）。
- さくらの管理画面で **MySQLデータベースを作成**し、接続情報を本番 `config.php` に設定（FTPで個別アップ）。
- `config.php` はリポジトリに含めない。GitHubは公開前提なので秘密情報の混入に注意。

---

## 9. Claude への作業ルール ★毎回守る
1. **コードを書く前に必ずプランを出し、合意を取る**。いきなり実装しない。
2. **勝手に先へ進まない**。1ステップずつ、合意してから次へ。
3. **学習目的**なので、何を・なぜそうするのかの**短い解説**を添える。専門用語は噛み砕く。
4. **シンプル優先**。仕様を超える機能を勝手に足さない。
5. 不明点・選択（スキーマ/ドライバ等）は、勝手に決めず**質問する**。

## 10. 進行状況・セッション運用 ★再開時にまずここを読む
- **GitHubリポジトリ**：enquete-v2 用に**新規作成予定**（前課題 kugenuma-enquete とは別）。URLは作成後にここへ記入。
- **コードの push 方針**：前回同様、要所でまとめて push（むやみに途中pushしない）。秘密情報混入チェックを必ず行う。
- **1 issue ずつ進める**。セッションは issue 達成ごとに切る。
- **まだ着手前の状態**：enquete のコードをコピーしただけ。DB化はこれから（issue未作成）。
- README.md は enquete（v1）の内容のまま。v2向けの更新が必要（タスク化する）。

### 再開手順（新セッションはこの順で状況把握）
1. この CLAUDE.md を読む（特に「0. 位置づけ」と「10. 進行状況」）
2. `gh issue list` で open/closed を確認 → 次のissueを決める
3. `git log --oneline` と `git branch` で進捗・作業中ブランチを確認
4. DB周りは config.php の接続情報と phpMyAdmin の状態も確認
