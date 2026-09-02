-- Invalida sessões antigas após troca de senha.
-- Execute uma única vez em bancos EVA já existentes.

ALTER TABLE users
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1
    AFTER status;
