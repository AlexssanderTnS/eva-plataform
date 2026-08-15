# Planejamento de deploy — EVA

## Estado atual

O bloco de **autenticação e conta** está funcionalmente concluído na branch `security/auth-hardening`.

A homologação final será executada depois que os próximos blocos da plataforma estiverem implementados, para que o teste ponta a ponta seja feito sobre a versão que realmente irá para produção.

Documentação detalhada:

- `deployment/authentication-account.md` — arquitetura, fluxos, banco, segurança, staging e estado de fechamento.
- `deployment/security-hardening.md` — checklist de segurança e deploy.

## Estratégia adotada

1. Desenvolver e estabilizar cada bloco funcional em branch própria.
2. Homologar os fluxos principais em staging durante o desenvolvimento.
3. Registrar bugs encontrados e corrigir no branch antes de seguir.
4. Finalizar os blocos restantes da plataforma.
5. Executar uma bateria única de testes completos em staging.
6. Preparar backup, migrations e regras de servidor.
7. Publicar em produção somente após checklist final aprovado.

## Bloco concluído: autenticação e conta

Entregue:

- cadastro;
- confirmação de e-mail;
- reenvio;
- login;
- sessão segura;
- área da conta;
- alteração de perfil;
- alteração de senha;
- invalidação de sessões antigas após troca de senha;
- logout;
- exportação de dados;
- endpoint de solicitação de exclusão;
- hardening HTTP e de arquivos;
- rate limiting implementado;
- CI de segurança/sintaxe.

A bateria de segurança completa permanece pendente para o fechamento geral da plataforma.

## Próximo bloco

**Mercado Pago + liberação de curso.**

Fluxo alvo:

`usuário autenticado → escolha do curso → pagamento → webhook/confirmacão → liberação/matrícula → curso exibido na área da conta`.

Depois desse bloco, integrar Moodle/SSO conforme a arquitetura definida para a plataforma.

## Produção — autenticação

No momento do deploy de autenticação para produção:

1. backup de arquivos e banco;
2. revisar `.htaccess` existente da produção e mesclar as regras de segurança;
3. executar `database/migrations/2026-08-14-auth-hardening.sql`;
4. executar `database/migrations/2026-08-15-session-version.sql`;
5. publicar os arquivos aprovados;
6. preservar `config/database.local.php` e `config/mail.local.php` locais;
7. garantir armazenamento de sessão fora do document root;
8. executar checklist de `deployment/security-hardening.md`;
9. validar fluxo completo em produção.

Não executar `database/schema.sql` sobre o banco de produção existente.

## Regra de ambientes

Staging utiliza banco, usuário de banco e configurações locais separados da produção.

Alterações manuais específicas do staging — domínio permitido e URLs de confirmação — não devem ser levadas para produção.

Arquivos compactados usados para upload devem ser removidos do document root após extração.
