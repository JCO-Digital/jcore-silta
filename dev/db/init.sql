-- Dev stack only: one MariaDB server holds both sites' databases.
-- Runs once, when the db volume is first initialised.
CREATE DATABASE IF NOT EXISTS staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'wordpress'@'%' IDENTIFIED BY 'wordpress';
GRANT ALL PRIVILEGES ON staging.* TO 'wordpress'@'%';
GRANT ALL PRIVILEGES ON production.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
