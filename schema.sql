-- schema.sql
-- enquete-v2 のデータベース構造（テーブル定義）の記録。
-- phpMyAdmin の「SQL」タブにこの内容を貼り付けて実行すると、テーブルが作れる。
-- ※ データベース本体（enquete_v2）は phpMyAdmin の「新規作成」で先に作っておく
--   （照合順序は utf8mb4_general_ci などでOK）。
--
-- ローカル(XAMPP/MariaDB)・本番(さくらMySQL)どちらでも同じSQLで作れる。

-- =====================================================================
-- responses : アンケートの回答を1件＝1行で保存するテーブル
-- =====================================================================
CREATE TABLE responses (
  id         INT          NOT NULL AUTO_INCREMENT,        -- 連番ID（主キー）
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP, -- 回答日時（省略時は現在時刻）
  frequency  VARCHAR(255) NOT NULL DEFAULT '',            -- Q1: 来る頻度
  purpose    TEXT,                                        -- Q2: 来る目的（自由記述）
  complaint  TEXT,                                        -- Q3: 不満・改善要望（自由記述）
  category   VARCHAR(50)  NOT NULL DEFAULT '未分類',       -- AI分類（5カテゴリ or 未分類）
  PRIMARY KEY (id)
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
