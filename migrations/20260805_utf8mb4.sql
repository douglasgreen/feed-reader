-- Migration: convert the FeedManager database from utf8 (utf8_unicode_ci) to
-- utf8mb4 (utf8mb4_unicode_ci) to match the app's PDO connection charset
-- (charset=utf8mb4). Without this, comparing a utf8_unicode_ci column against a
-- bound utf8mb4 parameter fails with
--   SQLSTATE[HY000]: General error: 1267 Illegal mix of collations.
--
-- Reverse indexes/constraints are unnecessary here since ALTER preserves them;
-- only column character sets and table defaults need converting.

USE FeedManager;

ALTER DATABASE FeedManager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE feeds
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE filters
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `groups`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE items
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
