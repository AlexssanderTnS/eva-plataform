-- EVA -> Moodle External Database Authentication
-- Preparacao NAO invasiva: cria somente uma visao de leitura para usuarios aptos.
-- Execute apenas apos backup e homologacao das regras com a equipe Moodle.
-- NAO inclui GRANT ou credenciais: o usuario MySQL dedicado deve ser criado no cPanel.
-- Mantem os usernames atuais do Moodle intactos ate um plano de migracao validado.

CREATE OR REPLACE VIEW eva_moodle AS
SELECT
    LOWER(TRIM(email)) AS username,
    password_hash AS password,
    LOWER(TRIM(email)) AS email,
    first_name AS firstname,
    last_name AS lastname,
    CONCAT('eva:', id) AS idnumber
FROM users
WHERE
    status = 'active'
    AND email_verified_at IS NOT NULL;

-- Conferencia sem mostrar hashes (execute no phpMyAdmin):
-- SELECT COUNT(*) AS usuarios_habilitados FROM eva_moodle;
-- SELECT username, email, firstname, lastname, idnumber FROM eva_moodle LIMIT 5;
-- ATENCAO: senha alterada na EVA sera refletida pela visao; teste no Moodle.
