# Recuperação de senha EVA

## Publicação no staging

1. Execute `database/migrations/2026-09-15-password-recovery.sql` no banco da EVA. O script apenas cria `password_reset_tokens`; não apaga dados existentes. Não execute o schema completo em um banco existente.
2. Publique `api/auth/forgot-password.php`, `api/auth/reset-password.php`, `recuperar-senha.html`, `css/password-recovery.css` e `js/password-recovery.js`.
3. Atualize `acesso.html` e `js/main.js` da mesma versão. A página de recuperação usa uma referência versionada do script para funcionar mesmo com uma sessão já aberta.
4. Preserve `config/mail.local.php`, `config/database.local.php` e as demais configurações locais. O envio usa o SMTP já configurado para a confirmação de cadastro, com SMTPS.

## Comportamento

- A solicitação retorna a mesma mensagem para conta existente, inexistente ou bloqueada.
- Limites: 10 solicitações por IP/hora, 3 por e-mail/hora e 20 tentativas de redefinição por IP/15 minutos.
- Token aleatório de 32 bytes, hash SHA-256 no banco, validade de 30 minutos e substituição do token anterior.
- Token enviado no fragmento do link, removido da barra de endereço pelo JavaScript e enviado ao endpoint apenas via POST. Reabrir o e-mail permite retomar uma página recarregada antes do envio.
- A redefinição bloqueia os registros em transação, valida estado da conta, validade e versão de sessão, atualiza o hash de senha e consome o token.
- `session_version` é incrementado: as sessões EVA antigas são rejeitadas na próxima requisição. Tokens emitidos antes de outra troca de senha também deixam de funcionar.
- Não faz login automático, não confirma e-mail, não modifica matrícula nem encerra uma sessão independente já aberta dentro do Moodle.
- Senhas seguem o mínimo de 8 caracteres. O limite de 72 bytes evita truncamento pelo bcrypt usado por PASSWORD_DEFAULT; caracteres multibyte podem atingir esse limite antes de 72 caracteres.

## Teste obrigatório antes de produção

1. Solicite recuperação para uma conta real de teste EVA e confira recebimento do e-mail, domínio do link e página em celular e desktop.
2. Compare a resposta com e-mail inexistente e bloqueado: a mensagem deve ser a mesma.
3. Envie senhas diferentes; deve recusar. Depois envie duas iguais: a antiga deve falhar e a nova deve permitir login.
4. Reabra o link consumido e tente novamente: deve recusar.
5. Solicite dois links: somente o mais recente deve funcionar.
6. Em uma conta de teste, deixe expirar o link; deve recusar.
7. Mantenha uma sessão EVA aberta em outro navegador antes da redefinição: a próxima chamada autenticada deve exigir login.
8. Se a conta não tiver e-mail confirmado, a recuperação não deve pular a confirmação de cadastro.
9. Confirme que o login, cadastro, navbar e retomada de compra existentes continuam funcionando.

## Verificação realizada na implementação

JavaScript validado com `node --check`. Testes com DOM e API simulados cobrem solicitação, formato inválido de token, remoção do fragmento, senhas divergentes, erro da API e sucesso com limpeza dos campos. PHP, SMTP, concorrência MySQL e aparência renderizada ainda precisam de teste no staging: PHP e navegador não estavam disponíveis no ambiente de desenvolvimento. Nenhum e-mail foi enviado durante esses testes.
