# Hardening de segurança — deploy HostGator

Este checklist acompanha a branch `security/auth-hardening`.

A documentação funcional completa do módulo está em `deployment/authentication-account.md`.

## Antes de publicar

- Fazer backup dos arquivos atuais e do banco.
- Manter `config/database.local.php` e `config/mail.local.php` fora do Git e sem acesso web.
- Não substituir o `.htaccess` raiz da produção sem preservar regras existentes de URLs limpas, redirects e handler PHP do HostGator.
- Não deixar ZIP, TAR ou outros arquivos de deploy no document root após a extração.
- Confirmar que `display_errors=Off`, `log_errors=On` e `expose_php=Off`.

## `.htaccess` raiz

A branch mantém:

```apache
Options -Indexes

<FilesMatch "(?i)^(?:\.env(?:\..*)?|\.gitignore|\.gitattributes|composer\.(?:json|lock)|package(?:-lock)?\.json|yarn\.lock|pnpm-lock\.yaml|phpunit\.xml(?:\.dist)?|error_log|.*\.(?:sql|log|ini|bak|backup|old|orig|save|swp))$">
    Require all denied
</FilesMatch>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule "(^|/)\.(?!well-known(?:/|$))" - [F,L]
    RewriteRule "(^|/)(?:config|database|deployment|tmp|vendor)(?:/|$)" - [F,L,NC]
</IfModule>
```

`.well-known` permanece liberado para AutoSSL/ACME.

As pastas `api/`, `config/`, `database/`, `tmp/` e `vendor/` também possuem regras locais de proteção.

## Sessões

`config/session.php` centraliza a configuração:

- cookie `EVA_SESSION`;
- `Secure`;
- `HttpOnly`;
- `SameSite=Lax`;
- `session.use_strict_mode=1`;
- somente cookies;
- timeout por inatividade de 2 horas;
- timeout absoluto de 12 horas;
- arquivos de sessão fora do `DOCUMENT_ROOT`.

Depois de publicar:

1. confirmar que o diretório externo `eva_sessions` foi criado e é gravável pelo PHP;
2. confirmar que não está dentro do document root;
3. remover o antigo `tmp/sessions/` somente após confirmar o novo armazenamento;
4. não registrar IDs de sessão em logs.

### Invalidação após troca de senha

A tabela `users` possui `session_version`.

O login grava essa versão na sessão. Toda sessão autenticada compara sua versão com o banco. A troca de senha incrementa o valor, mantendo válida somente a sessão que realizou a alteração.

Esse comportamento foi validado em staging com duas sessões simultâneas.

### Separação staging/produção

Se ambos os document roots tiverem o mesmo diretório pai, a implementação atual pode utilizar o mesmo diretório físico `eva_sessions` nos dois ambientes.

Antes do rollout final, preferir tornar o caminho configurável por ambiente (`eva_sessions_staging`, `eva_sessions`, etc.).

## Banco

### Banco novo

Importar somente:

`database/schema.sql`

### Banco existente

Executar uma única vez, nesta ordem:

1. `database/migrations/2026-08-14-auth-hardening.sql`
2. `database/migrations/2026-08-15-session-version.sql`

A primeira migration remove tokens duplicados antigos e cria unicidade de token de verificação por usuário.

A segunda adiciona `session_version` para invalidação das outras sessões após troca de senha.

Nunca importar `schema.sql` por cima de produção existente.

## Configuração por ambiente

A branch versionada usa origens e links de confirmação de produção.

No staging foram feitos overrides manuais:

- `config/security.php`: inclusão de `https://staging.evaglobal.com.br` nas origens permitidas;
- `api/auth/register.php`: link de verificação apontando para staging;
- `api/auth/resend-verification.php`: link de verificação apontando para staging.

Não copiar esses overrides para produção.

Como melhoria futura, origem e URL base devem ser configuráveis por ambiente.

## Testes que já passaram em staging

### Acesso a arquivos/diretórios

- `/database/schema.sql` → bloqueado.
- `/config/database.local.php` → bloqueado.
- `/config/mail.local.php` → bloqueado.
- `/vendor/composer/installed.json` → bloqueado.
- `/api/auth/error_log` → bloqueado.
- `/error_log` → bloqueado.
- `/composer.lock` → bloqueado.
- `/.git/config` → bloqueado.
- `/tmp/sessions/` → bloqueado.
- sessão confirmada fora do document root.
- ZIP de deploy removido do document root.

### Fluxo funcional

- cadastro novo;
- envio SMTP;
- recebimento de e-mail;
- confirmação de e-mail;
- login;
- carregamento de conta;
- atualização de perfil;
- troca de senha;
- logout;
- invalidação de outra sessão após troca de senha.

### Cookie

- `EVA_SESSION` presente;
- `Secure`;
- `HttpOnly`;
- `SameSite=Lax`.

### API e headers

Em `me.php` sem sessão:

- HTTP `401`;
- `Cache-Control` com `no-store`;
- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: DENY`;
- `Referrer-Policy: no-referrer`;
- CSP restritiva.

`me.php` autenticado devolveu somente nome, sobrenome, e-mail e status de verificação; não expôs IDs internos, hash de senha ou `session_version`.

## Testes finais ainda obrigatórios antes da produção

A bateria completa foi deliberadamente adiada para quando o restante da plataforma estiver fechado.

Executar:

- rate limit de login;
- rate limit de cadastro e reenvio;
- `Retry-After` em `429`;
- SQL injection controlada;
- IDOR em endpoints autenticados;
- origem não autorizada;
- `Content-Type` inválido;
- payload excessivo;
- cadastro duplicado e enumeração;
- reenvio de confirmação;
- token expirado/reutilizado;
- exportação de dados;
- solicitação de exclusão;
- conta bloqueada;
- timeouts de sessão;
- revisão de logs;
- `composer audit` imediatamente antes do deploy.

## CI

`.github/workflows/security-checks.yml` valida:

- Composer;
- dependências via `composer audit`;
- sintaxe PHP;
- sintaxe JavaScript;
- ausência de arquivos locais de segredo versionados;
- ausência de logs de ID de sessão.

O workflow passou após a correção de `session_version`.

## Checklist de produção

1. Backup de banco e arquivos.
2. Mesclar as regras do `.htaccess` com as regras atuais da produção.
3. Executar as duas migrations no banco existente.
4. Publicar os arquivos da branch aprovada.
5. Manter `database.local.php` e `mail.local.php` locais.
6. Validar criação do diretório de sessões fora do web root.
7. Executar a bateria final de segurança e funcional.
8. Validar cadastro → confirmação → login → conta → logout.
9. Validar novamente os caminhos sensíveis com `403/404`.
10. Só então considerar o bloco liberado para produção.
