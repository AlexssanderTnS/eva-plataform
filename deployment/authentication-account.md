# Autenticação e conta — fechamento técnico

Status: **implementação funcional concluída na branch `security/auth-hardening`; homologação final ponta a ponta será executada junto com o fluxo completo da plataforma antes da produção.**

Este documento registra o escopo, arquitetura, fluxos, segurança, banco, configuração, testes já executados em staging e pontos que ainda precisam ser revisitados na bateria final.

## 1. Escopo entregue

O módulo cobre:

- criação de conta;
- validação de nome, sobrenome, e-mail e senha;
- confirmação de e-mail por token;
- reenvio de confirmação;
- login;
- sessão autenticada;
- carregamento da área da conta;
- atualização de nome e sobrenome;
- alteração de senha;
- invalidação das outras sessões após troca de senha;
- logout;
- exportação dos dados visíveis da conta;
- endpoint para solicitação de exclusão de conta;
- proteção de arquivos e diretórios internos;
- headers de segurança, proteção de origem e rate limiting;
- CI de validação básica de segurança e sintaxe.

A integração de pagamento, matrícula/liberação de cursos e Moodle/SSO pertence ao próximo bloco funcional e não faz parte deste fechamento.

## 2. Arquivos principais

### Frontend

- `acesso.html` — interface de login e cadastro.
- `conta.html` — painel autenticado da conta.
- `css/auth.css` — estilos da autenticação.
- `css/account.css` — estilos da área da conta.
- `js/auth.js` — integração entre os formulários e a API.

### Backend de autenticação

- `api/auth/register.php`
- `api/auth/verify-email.php`
- `api/auth/resend-verification.php`
- `api/auth/login.php`
- `api/auth/me.php`
- `api/auth/update-profile.php`
- `api/auth/change-password.php`
- `api/auth/logout.php`
- `api/auth/export-data.php`
- `api/auth/request-account-deletion.php`

### Infraestrutura e segurança

- `config/database.php`
- `config/security.php`
- `config/session.php`
- `.htaccess`
- `api/.htaccess`
- `config/.htaccess`
- `database/.htaccess`
- `tmp/.htaccess`
- `vendor/.htaccess`
- `.github/workflows/security-checks.yml`

### Banco

- `database/schema.sql`
- `database/migrations/2026-08-14-auth-hardening.sql`
- `database/migrations/2026-08-15-session-version.sql`

## 3. Fluxo de cadastro

O frontend envia JSON para `POST /api/auth/register.php`.

O backend:

1. aceita somente `POST`;
2. exige origem confiável;
3. exige `Content-Type: application/json`;
4. limita o tamanho do corpo;
5. aplica rate limit por IP;
6. valida nome, sobrenome, e-mail e senha;
7. verifica se o domínio do e-mail possui resolução de correio/endereço;
8. consulta o usuário com prepared statement;
9. retorna resposta neutra quando o e-mail já existe;
10. gera `password_hash` com `PASSWORD_DEFAULT`;
11. gera token aleatório de confirmação com `random_bytes`;
12. armazena somente o SHA-256 do token no banco;
13. grava usuário e token dentro de transação;
14. encerra a transação antes de iniciar SMTP;
15. envia o e-mail via PHPMailer;
16. devolve resposta neutra ao navegador.

A resposta neutra reduz enumeração de contas. A aplicação não informa ao visitante se determinado e-mail já estava cadastrado.

## 4. Confirmação de e-mail

O link contém o token original somente na URL enviada por e-mail. O banco guarda apenas o hash.

`verify-email.php` valida o token, verifica expiração e, quando válido:

- preenche `email_verified_at`;
- remove o token utilizado;
- informa sucesso ao usuário.

O token é válido por 24 horas.

A tabela de tokens mantém no máximo um token ativo por usuário.

## 5. Reenvio da confirmação

`POST /api/auth/resend-verification.php`:

- aceita JSON;
- exige origem confiável;
- aplica rate limit por IP;
- devolve mensagem neutra para conta inexistente ou já verificada;
- evita reenvios imediatos sucessivos;
- remove o token anterior antes de criar um novo;
- envia o novo link por SMTP.

## 6. Login

`POST /api/auth/login.php`:

- valida e-mail e senha;
- usa prepared statement;
- executa `password_verify`;
- usa hash fictício quando a conta não existe para reduzir diferença de tempo observável;
- aplica rate limit por IP e por conta;
- exige conta `active`;
- exige e-mail confirmado;
- regenera o ID de sessão após autenticação;
- grava `user_id` e `session_version` na sessão;
- não devolve IDs internos, hash de senha ou outros dados da conta no payload de sucesso.

Configuração atual do login:

- até 25 falhas por IP em uma janela de 15 minutos;
- até 8 falhas por conta em uma janela de 15 minutos;
- quando o limite é atingido, a API retorna HTTP `429` e `Retry-After`.

O contador da conta é limpo após autenticação correta.

## 7. Sessões

As sessões são inicializadas por `config/session.php`.

Características:

- cookie chamado `EVA_SESSION`;
- `Secure` habilitado;
- `HttpOnly` habilitado;
- `SameSite=Lax`;
- somente cookies para transportar o ID de sessão;
- `session.use_strict_mode=1`;
- regeneração de ID no login e na troca de senha;
- timeout por inatividade de 2 horas;
- timeout absoluto de 12 horas;
- arquivos de sessão armazenados fora do `DOCUMENT_ROOT`.

O diretório é criado no diretório pai do document root com nome `eva_sessions` e permissão restritiva.

### Invalidação de outras sessões

A tabela `users` possui `session_version`.

No login, a versão atual é copiada para a sessão. Em toda inicialização autenticada, `config/session.php` compara a versão da sessão com a versão do banco.

Ao trocar a senha:

1. o hash da senha é atualizado;
2. `session_version` é incrementado;
3. a sessão que realizou a troca recebe a nova versão;
4. qualquer outra sessão ainda contém a versão antiga e é destruída na próxima requisição.

Esse comportamento foi validado no staging com duas sessões simultâneas.

## 8. Área da conta

`conta.html` inicia oculta e só é exibida depois de `GET /api/auth/me.php` retornar uma conta autenticada válida.

`me.php` devolve apenas:

- `first_name`;
- `last_name`;
- `email`;
- `email_verified`.

Não são enviados ao navegador:

- `id`;
- `moodle_user_id`;
- `password_hash`;
- `session_version`.

Se a sessão for inválida, expirada ou a conta estiver indisponível, o frontend volta para `acesso.html`.

## 9. Atualização de perfil

`PATCH /api/auth/update-profile.php` permite alterar apenas nome e sobrenome.

O usuário é identificado pela sessão, não por ID fornecido pelo navegador. Isso evita o padrão clássico de IDOR em que um cliente tenta alterar outro usuário mudando um identificador na requisição.

O e-mail não é editável por este fluxo.

## 10. Alteração de senha

`POST /api/auth/change-password.php` exige:

- sessão válida;
- versão de sessão válida;
- senha atual correta;
- nova senha com 8 a 128 caracteres;
- confirmação idêntica;
- nova senha diferente da senha atual.

Há rate limit por usuário e por IP.

Após sucesso, outras sessões são invalidadas por `session_version`.

## 11. Logout

`POST /api/auth/logout.php` exige origem confiável e chama a destruição centralizada da sessão.

A função:

- limpa `$_SESSION`;
- expira o cookie;
- destrói a sessão do servidor;
- limpa o ID atual.

## 12. Exportação e exclusão

### Exportação

`GET /api/auth/export-data.php` gera JSON contendo os dados de conta necessários ao usuário e evita expor identificadores internos como `id` e `moodle_user_id`.

O frontend baixa o resultado como `eva-meus-dados.json`.

### Solicitação de exclusão

`POST /api/auth/request-account-deletion.php` existe no backend e exige confirmação de senha. A solicitação é gravada em `account_deletion_requests`.

O endpoint está preparado, mas o painel atual ainda não expõe um botão de exclusão. Sua validação visual/funcional pode ser feita quando esse fluxo entrar na interface ou na bateria final de API.

## 13. Banco de dados

### `users`

Campos relevantes para autenticação:

- `id`;
- `first_name`;
- `last_name`;
- `email` único;
- `email_verified_at`;
- `password_hash`;
- `moodle_user_id` opcional;
- `status` (`active` ou `blocked`);
- `session_version`;
- timestamps.

### `email_verification_tokens`

- token é armazenado como hash;
- token é único;
- existe no máximo um token por usuário;
- `ON DELETE CASCADE` remove tokens quando o usuário é removido.

### `account_deletion_requests`

Mantém a solicitação de exclusão e seu status de processamento.

## 14. Migrações

### Banco novo

Para banco vazio, importar somente:

`database/schema.sql`

O schema já contém a estrutura atual, incluindo `session_version` e unicidade do token por usuário.

### Banco existente

Executar, nesta ordem:

1. `database/migrations/2026-08-14-auth-hardening.sql`
2. `database/migrations/2026-08-15-session-version.sql`

A primeira limpa tokens duplicados e adiciona unicidade por usuário. A segunda adiciona `session_version`.

Nunca importar `schema.sql` por cima do banco de produção existente.

## 15. Configuração local e segredos

Os arquivos abaixo são locais e não entram no Git:

- `config/database.local.php`
- `config/mail.local.php`
- `.env` se futuramente utilizado.

Eles devem permanecer fora de commits, screenshots e documentação pública.

`database.local.php` contém conexão MySQL.

`mail.local.php` contém SMTP, remetente e credenciais.

O workflow de CI verifica que esses arquivos não foram versionados.

## 16. Proteção HTTP

`config/security.php` centraliza:

- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: DENY`;
- `Referrer-Policy: no-referrer`;
- `Cross-Origin-Resource-Policy: same-origin`;
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`;
- `Pragma: no-cache`;
- CSP restritiva para endpoints da API;
- validação de origem;
- validação de `Sec-Fetch-Site` quando presente;
- exigência de JSON para endpoints que recebem JSON;
- limite de tamanho do corpo;
- rate limiting com arquivos fora da árvore do projeto.

## 17. Proteção de arquivos e diretórios

O `.htaccess` raiz:

- desabilita listagem de diretórios;
- bloqueia dotfiles/dotdirs, preservando `.well-known` para AutoSSL;
- bloqueia arquivos de configuração, logs, SQL, backups e metadados;
- bloqueia acesso web direto a `config/`, `database/`, `deployment/`, `tmp/` e `vendor/`.

As subpastas possuem regras adicionais.

Regra operacional: arquivos ZIP/TAR usados em deploy não devem permanecer no document root. O ZIP utilizado na montagem do staging foi removido após a extração.

## 18. SQL injection, IDOR e exposição de dados

As consultas atuais de autenticação usam prepared statements e o PDO está configurado sem emulação de prepares.

Não há SQL construído a partir de nomes de tabela/coluna fornecidos pelo usuário.

Nos endpoints autenticados, o usuário é derivado da sessão. O navegador não escolhe `user_id` para ler ou editar a conta.

O navegador consegue visualizar somente os dados que a API devolve; ele não possui acesso direto às tabelas MySQL.

## 19. Staging criado para homologação

Foi criado um ambiente isolado em subdomínio de staging com:

- document root separado da produção;
- banco MySQL separado;
- usuário MySQL separado;
- SSL ativo;
- `database.local.php` próprio;
- `mail.local.php` próprio;
- schema importado em banco vazio;
- código da branch `security/auth-hardening`.

### Configuração automática por ambiente

A configuração de domínio foi centralizada em `config/app.php`.

O ambiente é resolvido pelo host conhecido da aplicação (produção ou staging), com suporte a override explícito por `EVA_APP_ENV` para desenvolvimento. A mesma configuração fornece:

- URL base canônica;
- origens permitidas;
- namespace físico das sessões.

Com isso, `config/security.php`, os links de confirmação de e-mail e o armazenamento de sessão deixam de exigir edição manual específica do staging.

## 20. Testes já executados no staging

### Infraestrutura e exposição

Passaram:

- página inicial do staging;
- `acesso.html`;
- `/api/auth/me.php` anônimo retornando `401`;
- `/database/schema.sql` bloqueado;
- `/config/database.local.php` bloqueado;
- `/config/mail.local.php` bloqueado;
- `/vendor/composer/installed.json` bloqueado;
- `/api/auth/error_log` bloqueado;
- `/error_log` bloqueado;
- `/composer.lock` bloqueado após inclusão do `.htaccess` raiz;
- `/.git/config` bloqueado;
- `/tmp/sessions/` bloqueado;
- arquivos de sessão confirmados fora do document root;
- ZIP de deploy removido do document root.

### Cadastro e e-mail

Passaram:

- criação de conta em banco de staging;
- envio SMTP;
- recebimento do e-mail;
- link de confirmação apontando para staging;
- confirmação de e-mail;
- login após confirmação.

### Sessão e conta

Passaram:

- cookie `EVA_SESSION` com `Secure`;
- cookie `EVA_SESSION` com `HttpOnly`;
- `SameSite=Lax`;
- `me.php` devolvendo somente os campos públicos da conta;
- logout destruindo a sessão;
- atualização de perfil persistindo após reload;
- troca de senha;
- duas sessões simultâneas;
- troca de senha invalidando a outra sessão após a correção de `session_version`.

### Headers

Passaram em `me.php`:

- HTTP `401` sem sessão;
- `Cache-Control` com `no-store`;
- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: DENY`;
- `Referrer-Policy: no-referrer`;
- `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'`.

## 21. Incidente encontrado durante o staging

O primeiro cadastro falhou porque o username do banco de staging continha um espaço inicial na configuração local. O erro foi identificado no log e corrigido no arquivo local.

Durante o teste de troca de senha, uma segunda sessão permaneceu ativa. A implementação foi revisada e ficou constatado que a versão inicial apenas regenerava o ID da sessão atual.

Correção aplicada:

- coluna `session_version` no banco;
- gravação da versão no login;
- validação centralizada em `config/session.php`;
- incremento da versão em `change-password.php`;
- migration `2026-08-15-session-version.sql`.

O reteste com duas sessões passou.

## 22. Testes adiados para a bateria final

A decisão do projeto é finalizar primeiro o restante do site e depois executar uma rodada única de homologação completa.

Ainda devem ser executados antes da produção:

- rate limit de login e `Retry-After`;
- rate limit de cadastro e reenvio;
- tentativas controladas de SQL injection;
- testes de IDOR em todos os endpoints autenticados;
- requisições com `Origin` não autorizado;
- requisições POST/PATCH sem JSON ou com `Content-Type` incorreto;
- payload acima do limite;
- teste completo de reenvio de confirmação;
- comportamento de cadastro duplicado e respostas neutras;
- exportação de dados;
- endpoint de solicitação de exclusão;
- conta bloqueada;
- token expirado e token reutilizado;
- expiração por inatividade e expiração absoluta da sessão;
- revisão mobile/desktop do fluxo completo;
- revisão dos logs para confirmar ausência de IDs de sessão;
- `composer audit` imediatamente antes do deploy final.

## 23. CI

`.github/workflows/security-checks.yml` executa na branch de hardening:

- versão de runtime;
- `composer validate --no-check-publish`;
- `composer audit --locked --no-interaction`;
- sintaxe PHP;
- sintaxe de todos os arquivos `.js` em `js/`;
- verificação de que arquivos locais de segredo não estão versionados;
- busca por logs de ID de sessão.

O workflow passou após as correções de `session_version`.

## 24. Considerações antes da produção

Antes do deploy final:

1. criar backup de arquivos e banco;
2. revisar e mesclar as regras de segurança no `.htaccess` existente da produção — não sobrescrever regras de URLs limpas/handler PHP sem revisão;
3. executar as duas migrations no banco existente;
4. manter `database.local.php` e `mail.local.php` locais;
5. garantir `display_errors=Off`, `log_errors=On` e `expose_php=Off`;
6. remover arquivos antigos de `tmp/sessions` após confirmar o novo armazenamento;
7. remover logs antigos que contenham IDs de sessão depois de preservar apenas o que for necessário para diagnóstico;
8. executar a bateria final de testes;
9. verificar novamente que arquivos internos retornam `403/404`;
10. validar cadastro, confirmação, login, conta e logout na produção.

### Diretório de sessões por ambiente

`config/session.php` utiliza o namespace fornecido por `config/app.php`, separando fisicamente `eva_sessions_staging`, `eva_sessions_production` e o namespace de desenvolvimento quando aplicável.

## 25. Estado de fechamento

Para desenvolvimento, o bloco **login + cadastro + conta** está fechado e pronto para o restante da plataforma depender dele.

Não significa que a produção esteja liberada neste momento: a homologação final de segurança foi deliberadamente adiada para ser executada quando pagamento, liberação de curso e integrações estiverem completos, evitando repetir a bateria inteira após cada alteração.

O bloco de Mercado Pago Checkout Pro já foi implementado na branch `integration/mercado-pago`. O próximo bloco funcional é **Moodle/SSO + matrícula/liberação definitiva do curso**.
