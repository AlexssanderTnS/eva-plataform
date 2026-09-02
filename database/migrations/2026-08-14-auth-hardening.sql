-- Hardening de autenticação EVA
-- Mantém somente o token de verificação mais recente por usuário

DELETE older_token
FROM email_verification_tokens AS older_token
INNER JOIN email_verification_tokens AS newer_token
    ON newer_token.user_id = older_token.user_id
   AND newer_token.id > older_token.id;

ALTER TABLE email_verification_tokens
    ADD UNIQUE KEY uq_email_verification_user (user_id);
