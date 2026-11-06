-- Database dei test: separato da quello di sviluppo, perché RefreshDatabase lo svuota.
CREATE DATABASE IF NOT EXISTS contabilita_test;
GRANT ALL ON contabilita_test.* TO 'contabilita'@'%';
