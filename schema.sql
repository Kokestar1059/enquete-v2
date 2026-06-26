-- schema.sql
-- enquete-v2 のデータベース構造（テーブル定義）の記録。
-- phpMyAdmin の「SQL」タブにこの内容を貼り付けて実行すると、テーブルが作れる。
-- ※ データベース本体（enquete_v2）は phpMyAdmin の「新規作成」で先に作っておく
--   （照合順序は utf8mb4_general_ci などでOK）。
--
-- ローカル(XAMPP/MariaDB)・本番(さくらMySQL)どちらでも同じSQLで作れる。

-- =====================================================================
-- categories : カテゴリのマスタテーブル（#7で追加）
--   カテゴリ名を1か所に集約し、responses からは id で参照する（正規化）。
--   name は UNIQUE … 同じカテゴリ名が二重登録されるのを防ぐ。
--   ※ responses が外部キーで参照するので、responses より先に作る。
-- =====================================================================
CREATE TABLE categories (
  id   INT         NOT NULL AUTO_INCREMENT,               -- 連番ID（主キー）
  name VARCHAR(50) NOT NULL,                              -- カテゴリ名（表示名）
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)                    -- 名前の重複禁止
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 初期カテゴリ（5カテゴリ＋未分類）を投入する。
INSERT INTO categories (name) VALUES
  ('インフラ'), ('自然環境'), ('安全'), ('飲食・施設'), ('その他'), ('未分類');

-- =====================================================================
-- responses : アンケートの回答を1件＝1行で保存するテーブル
-- =====================================================================
CREATE TABLE responses (
  id          INT          NOT NULL AUTO_INCREMENT,        -- 連番ID（主キー）
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP, -- 回答日時（省略時は現在時刻）
  frequency   VARCHAR(255) NOT NULL DEFAULT '',            -- Q1: 来る頻度
  purpose     TEXT,                                        -- Q2: 来る目的（自由記述）
  complaint   TEXT,                                        -- Q3: 不満・改善要望（自由記述）
  category    VARCHAR(50)  NOT NULL DEFAULT '未分類',       -- AI分類の文字列（#7後はcategory_idが正。当面は併存）
  category_id INT          NOT NULL,                        -- カテゴリ（categories.id を参照）#7で追加
  PRIMARY KEY (id),
  CONSTRAINT fk_responses_category                          -- 存在しないカテゴリを弾く外部キー制約
    FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- analysis : AIによる全体分析の文章を保存するテーブル
--   方式は「履歴を持つ」… 再分析するたびに1行ずつ INSERT で追加する。
--   表示は最新1行だけ（ORDER BY created_at DESC LIMIT 1）を読む。
--   過去の分析も残るので、上書きより安全で、INSERTだけなので実装も素直。
-- =====================================================================
CREATE TABLE analysis (
  id         INT      NOT NULL AUTO_INCREMENT,            -- 連番ID（主キー）
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, -- 分析日時（省略時は現在時刻）
  content    TEXT     NOT NULL,                           -- 分析文（AIが生成した本文）
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- rate_limits : analyze.php の連打対策（レート制限）の記録テーブル（#6で追加）
--   analyze.php を呼ぶたびに「ip + 時刻」を1行INSERTするログ方式。
--   ・直近10秒に同IPの行があれば連打とみなす（10秒に1回まで）
--   ・直近24時間に同IPが100件以上なら1日の上限（1日100回まで）
--   どちらも「このテーブルを数えるだけ」で判定でき、実装がシンプル。
--   ※ アンケートの中身(responses等)とは無関係な運用ログなので別テーブルにする。
--     古い行は analyze.php 側で定期的に掃除する（DELETE）。
-- =====================================================================
CREATE TABLE rate_limits (
  id         INT         NOT NULL AUTO_INCREMENT,         -- 連番ID（主キー）
  ip         VARCHAR(45) NOT NULL,                        -- 呼び出し元IP（IPv6も入る長さ）
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP, -- 呼び出し時刻（省略時は現在時刻）
  PRIMARY KEY (id),
  KEY idx_ip_created (ip, created_at)                     -- ip+時刻での絞り込みを速くする索引
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- 【参考】#7 移行手順（既に responses があるDBを、上の新構造へ移すSQL）
--   ↑のCREATE文は「まっさらから作り直す用」。すでにデータが入っているDBは
--   テーブルを作り直さず、下を順に流して既存データを保ったまま移行する。
-- =====================================================================
-- -- 1) categories を作って6カテゴリを投入（上の categories の CREATE/INSERT と同じ）
-- -- 2) responses に category_id を追加（まずNULL可で足す）
-- ALTER TABLE responses ADD COLUMN category_id INT NULL AFTER category;
-- -- 3) 文字列 category に対応する categories.id を埋める
-- UPDATE responses r JOIN categories c ON c.name = r.category SET r.category_id = c.id;
-- -- 4) 一致しなかった行は「未分類」に寄せる（安全策）
-- UPDATE responses SET category_id = (SELECT id FROM categories WHERE name='未分類')
--   WHERE category_id IS NULL;
-- -- 5) NOT NULL 化
-- ALTER TABLE responses MODIFY COLUMN category_id INT NOT NULL;
-- -- 6) 外部キー制約を追加（存在しないカテゴリを弾く）
-- ALTER TABLE responses ADD CONSTRAINT fk_responses_category
--   FOREIGN KEY (category_id) REFERENCES categories(id);
