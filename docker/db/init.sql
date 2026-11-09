-- Database dei test: separato da quello di sviluppo, perché RefreshDatabase lo svuota.
CREATE DATABASE IF NOT EXISTS contabilita_test;
GRANT ALL ON contabilita_test.* TO 'contabilita'@'%';
-- Database di prova dell'onboarding (ADR 0003), anche questo separato: i test lo svuotano da soli.
CREATE DATABASE IF NOT EXISTS contabilita_test_prova;
GRANT ALL ON contabilita_test_prova.* TO 'contabilita'@'%';
-- E lo stesso per lo sviluppo e la copia locale di Aruba: contabilita_prova.
CREATE DATABASE IF NOT EXISTS contabilita_prova;
GRANT ALL ON contabilita_prova.* TO 'contabilita'@'%';
