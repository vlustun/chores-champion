-- Runs once on first container start. Dev DB is created via MYSQL_DATABASE.
CREATE DATABASE IF NOT EXISTS chores_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON chores_test.* TO 'chores'@'%';
GRANT ALL PRIVILEGES ON `chores_test%`.* TO 'chores'@'%';
FLUSH PRIVILEGES;
