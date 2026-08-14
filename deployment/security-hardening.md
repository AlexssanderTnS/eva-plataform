# Hardening de segurança — deploy HostGator

Este checklist acompanha a branch `security/auth-hardening`.

## Antes de publicar

- Fazer backup dos arquivos atuais e do banco.
- Manter `config/database.local.php` e `config/mail.local.php` fora do Git e sem acesso web.
- Não substituir o `.htaccess` raiz sem preservar as regras atuais de URLs limpas e o handler PHP do HostGator.

## Regras que o `.htaccess` raiz deve manter

```apache
Options -Indexes

<FilesMatch "^(\.env|\.htpasswd|error_log|composer\.(json|lock)|.*\.local\.php|.*\.(sql|log|bak|old|ini))$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

RewriteEngine On
RewriteRule (^|/)\.git(?:/|$) - [F,L]
```

As pastas `config/`, `database/`, `vendor/`, `tmp/` e `api/` recebem proteções adicionais nesta branch.

## Sessões

A aplicação passa a armazenar sessões em `eva_sessions`, no diretório pai do `DOCUMENT_ROOT`, portanto fora do `public_html`.

Depois de publicar a branch:

1. Confirmar que o diretório externo `eva_sessions` foi criado e é gravável pelo PHP.
2. Apagar os arquivos antigos de `public_html/tmp/sessions/`.
3. Não reutilizar o diretório antigo como `session.save_path`.

Isso invalida as sessões antigas e elimina o risco de arquivos de sessão permanecerem na árvore pública.

## PHP de produção

No painel de PHP do HostGator, manter:

```ini
display_errors = Off
log_errors = On
expose_php = Off
```

Os logs devem ficar fora de diretórios públicos sempre que o painel permitir.

## Banco

Executar uma vez:

`database/migrations/2026-08-14-auth-hardening.sql`

A migration remove tokens de verificação duplicados antigos e cria unicidade de token por usuário.

## Testes após deploy

- Cadastro novo envia confirmação.
- Cadastro com e-mail existente retorna resposta neutra.
- Reenvio de confirmação retorna resposta neutra independentemente da existência da conta.
- Login correto cria o cookie `EVA_SESSION` com `Secure`, `HttpOnly` e `SameSite=Lax`.
- Login inválido repetido passa a responder `429` ao atingir o limite.
- `/api/auth/me.php` sem sessão retorna `401`.
- Perfil, senha e logout recusam requisições de origem não autorizada.
- `/database/schema.sql`, `/config/database.local.php`, `/config/mail.local.php`, `/vendor/composer/installed.json`, `/tmp/` e `/api/error_log` devem responder `403` ou `404`.
- Nenhum `error_log` deve conter ID de sessão.
