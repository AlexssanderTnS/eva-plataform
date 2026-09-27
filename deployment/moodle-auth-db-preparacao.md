# EVA — Preparação para login direto pelo Moodle sem perder o SSO

**Estado:** preparação. Nenhuma mudança na autenticação em produção está autorizada apenas por este documento.

## Implementação em branch de preparação (não publicar ainda)

O provisionamento foi preparado com uma opção `auth_mode` no arquivo **local** `config/moodle.local.php`:
- Sem `auth_mode` (padrão `manual`): comportamento anterior preservado, inclusive `username=eva_<id>`.
- `'auth_mode' => 'db'`: apenas NOVOS usuários criados pelo provisionamento terão `username` igual ao e-mail normalizado e `auth=db`. O `idnumber=eva:<id>` permanece.
- Antes da criação `db`, o código consulta o Moodle pelo username/e-mail; em caso de colisão, interrompe para revisão em vez de vincular a conta errada.
- Usuários Moodle já existentes são localizados por `idnumber` e atualizados SOMENTE em dados cadastrais, sem troca automática de `username`/`auth` (migração precisa de procedimento separado).
- Ative o modo `db` somente após Thiago confirmar compatibilidade com User Key, concluir configuração do plugin de banco externo e após teste da migração em homologação.
- Não compartilhar senha MySQL no GitHub nem copiar essa opção para produção prematuramente.
- Atenção: branch derivada de `main`. Comparar com os arquivos efetivamente implantados na HostGator antes de qualquer merge ou deploy.

## Objetivo

Disponibilizar as duas entradas para a **mesma conta Moodle**:
- Site EVA autenticado -> SSO existente via `auth_userkey_request_login_url` -> Moodle, sem novo login.
- Acesso direto ao Moodle -> plugin `auth_db` verifica e-mail e hash de senha na visão somente leitura da EVA.

Moodle autentica; compras, matrícula e controle de acesso permanecem na EVA/fluxo atual. Login não deve conceder matrícula automaticamente.

## Situação identificada no código

- `api/auth/register.php` usa `password_hash($password, PASSWORD_DEFAULT)`. Confirmar hash de teste no PHP de produção: atualmente espera-se bcrypt `$2y$` (não enviar hash completo).
- `users`: `id`, `email`, `password_hash`, `first_name`, `last_name`, `status`, `email_verified_at`.
- `config/moodle-service.php` encontra usuário pelo `idnumber=eva:<id>`, cria `username=eva_<id>` e `auth=manual`; **não alterar para e-mail/db sem migração dos registros existentes**.
- `api/account/sso.php` utiliza `auth_userkey_request_login_url` com `idnumber`.

## 1. Preparação do lado EVA

1. Fazer backup do banco e confirmar as configurações no ambiente de testes.
2. Revisar, com suporte Moodle, política de contas pendentes de verificação/bloqueadas: a visão proposta inclui SOMENTE contas `active` e e-mail verificado.
3. Executar `database/migrations/2026-09-26-moodle-auth-db-view.sql` **apenas no ambiente selecionado**; ele cria a visão `eva_moodle`, não altera `users` nem usuários Moodle.
4. Checagem sem exportar hashes:
   ```sql
   SHOW FULL TABLES LIKE 'eva_moodle';
   SELECT COUNT(*) AS aptos FROM eva_moodle;
   SELECT username, email, firstname, lastname, idnumber FROM eva_moodle LIMIT 5;
   ```
5. Pelo cPanel, criar novo usuário MySQL exclusivo para o Moodle (nome real com prefixo definido pelo cPanel). Configurar acesso `SELECT` **somente na visão** `eva_moodle`. Atenção: alguns painéis de hospedagem concedem privilégios por banco inteiro; se não permitir restringir à visão, **não conceder ALL PRIVILEGES** nem compartilhar o usuário do aplicativo. Pedir suporte HostGator para concessão SQL de escopo mínimo.
6. Confirmar hospedagem, porta, TLS/certificados, acesso MySQL remoto e allowlist **somente para o IP 186.227.199.34** informado pelo suporte, após confirmar diretamente com a equipe Moodle. O firewall pode ser controlado pela hospedagem. Não presumir acesso externo só porque phpMyAdmin funciona.
7. Testar conectividade em conjunto com suporte, via canal seguro. Nunca salvar usuário/senha MySQL em repositório, chat ou documentação.

## 2. Dados a encaminhar ao suporte

- SGBD: MySQL/MariaDB; **host/porta reais: confirmar com HostGator**.
- Banco: nome real do banco de produção (confirmar no cPanel).
- Visão: `eva_moodle`.
- Campo de usuário: `username` (e-mail EVA normalizado).
- Campo da senha: `password` (hash PHP; confirmar bcrypt com hash de teste, sem divulgar senha).
- Mapeamento opcional: `email`, `firstname`, `lastname`, `idnumber`.
- Usuário de conexão: conta MySQL dedicada somente leitura da visão. Enviar a senha somente pelo canal seguro combinado.
- Formato Moodle proposto: **Salted Crypt** para bcrypt, condicionado ao teste na versão instalada pelo suporte. Não escolher MD5, SHA-1, senha em texto puro ou formato internal.
- Alteração de senha: manter na EVA; configurar URL de troca de senha para o site EVA. Desabilitar update externo e evitar criar/sincronizar contas automaticamente antes do teste.

## 3. Questões obrigatórias para Thiago (suporte Moodle)

1. `auth_userkey_request_login_url` / plugin User Key funciona com os mesmos usuários se `auth=db`, mantendo o `idnumber=eva:<id>`? Testar com uma conta de homologação antes da migração.
2. A versão instalada do plugin `auth_db` valida os hashes bcrypt (`$2y$`) usando `Salted Crypt`?
3. Podemos manter o `idnumber` para preservar a vinculação com a EVA e alterar o username dos usuários Moodle existentes para o e-mail, sem recriar contas?
4. Qual procedimento de migração em lote e de detecção de conflitos de e-mail/username será adotado? Nenhuma conta deve ser recriada só por diferença de username.
5. Confirmar TLS/conexão remota, IP de origem e método seguro de troca da senha de conexão.

## 4. Plano de alteração futura (NÃO executar nesta etapa)

1. Homologar `auth_db` e `auth_userkey` juntos com UMA conta de teste.
2. Inventariar os usuários Moodle existentes (`id`, `username`, `email`, `idnumber`, `auth`) e os `moodle_user_id` gravados na EVA. Resolver duplicações manualmente antes de atualizar.
3. Em manutenção coordenada, migrar `username` de `eva_<id>` para e-mail e `auth` de `manual` para `db` para usuários correspondentes. **Preservar IDs Moodle e `idnumber`**.
4. Ajustar `evaMoodleCreateUser()` para os novos cadastros (username=e-mail, auth=db), mas manter `evaMoodleFindUserByEvaId()` como chave imutável. Tratar alterações de e-mail e a atualização do username com verificação de conflito.
5. Testar login direto, SSO, alteração de senha EVA, usuários sem compra, matrícula após pagamento, acesso já comprado, bloqueio/exclusão e recuperação de conta.
6. Revisar comportamento da tarefa de sincronização `auth_db` antes de habilitá-la; a documentação do Moodle informa que ela pode criar/atualizar contas. Evitar que concorra com provisionamento da EVA.

## Critérios de aceite

- Login direto pelo Moodle com **a mesma senha da EVA**.
- SSO pelo botão da EVA continua abrindo o mesmo usuário Moodle sem pedir senha novamente.
- Mesmo `idnumber`, mesmo `moodle_user_id` e mesmas matrículas nos dois caminhos.
- Usuário não verificado ou bloqueado não autentica via banco externo.
- Nenhuma credencial de banco exposta; acesso remoto restrito, cifrado e com `SELECT` só na visão.
- Compra de teste validada por Juliana: pedido -> confirmação Mercado Pago/webhook -> curso liberado -> acesso pelos dois caminhos.

**Observação:** esta preparação não realiza acesso remoto, não cria o usuário MySQL nem altera produção automaticamente.
