-- Executed once, on the first start of an empty MySQL volume.
-- The test environment uses the "patchnotes_test" database (Doctrine dbname_suffix "_test",
-- optionally followed by a ParaTest token), so the app user gets access to all "patchnotes_*" schemas.
CREATE DATABASE IF NOT EXISTS `patchnotes_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON `patchnotes\_%`.* TO 'patchnotes'@'%';
FLUSH PRIVILEGES;
