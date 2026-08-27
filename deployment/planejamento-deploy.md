# Planejamento de deploy — EVA

## Estado atual

Os blocos de **autenticação e conta** e de **Mercado Pago Checkout Pro** estão funcionalmente implementados na linha de desenvolvimento que culmina na branch `integration/mercado-pago`.

A autenticação já foi validada em staging. A integração de pagamento está pronta para a homologação de Checkout Pro no staging, após aplicação da migration e configuração do webhook/segredos de teste.

A homologação final de segurança e do fluxo ponta a ponta continuará sendo executada sobre a versão que realmente irá para produção.

Documentação detalhada:

- `deployment/authentication-account.md` — arquitetura, fluxos, banco, segurança, staging e estado da autenticação.
- `deployment/mercado-pago-checkout-pro.md` — arquitetura do Checkout Pro, configuração, webhook e roteiro de homologação.
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

## Bloco implementado: Mercado Pago Checkout Pro

Entregue no código:

- catálogo comercial com preço vindo do banco;
- criação e reutilização segura de pedido interno;
- criação e reutilização de Preference do Checkout Pro;
- redirecionamento para o checkout hospedado do Mercado Pago;
- URLs de retorno de sucesso, pendência e falha;
- página única de retorno do pagamento;
- consulta autenticada do status do pedido;
- webhook de pagamentos com validação de assinatura;
- confirmação do pagamento por consulta à API do Mercado Pago;
- persistência de pedidos, pagamentos e eventos de webhook;
- criação de `course_access` como `pending` após pagamento aprovado;
- migrations específicas para adaptação ao Checkout Pro e hardening de concorrência do webhook;
- documentação e CI atualizados.

Fluxo implementado:

`usuário autenticado → escolha do curso → pedido EVA → Preference → Checkout Pro → retorno/webhook → confirmação pela API → acesso pendente`

Pendente para homologação em staging:

1. executar `database/migrations/2026-08-19-checkout-pro.sql` e `database/migrations/2026-08-27-commerce-hardening.sql`, nessa ordem;
2. configurar `config/mercadopago.local.php` com credenciais de teste e `webhook_secret`;
3. configurar o evento Pagamentos no painel do Mercado Pago para o webhook de staging;
4. publicar os arquivos da branch no staging;
5. executar compra de teste ponta a ponta;
6. validar `orders`, `payments`, `payment_webhook_events` e `course_access`.

## Próximo bloco funcional

**Moodle/SSO + matrícula/liberação definitiva do curso.**

O pagamento aprovado já deixa o acesso em estado `pending`. O próximo bloco deve consumir esse estado, criar/sincronizar a matrícula no Moodle e somente então marcar o acesso como `active`.

## Produção — autenticação e comércio

No momento do deploy final para produção:

1. fazer backup de arquivos e banco;
2. revisar `.htaccess` existente da produção e mesclar as regras de segurança;
3. executar as migrations ainda não aplicadas, na ordem cronológica;
4. preservar os arquivos locais `config/database.local.php`, `config/mail.local.php` e `config/mercadopago.local.php`;
5. garantir armazenamento de sessão fora do document root;
6. configurar credenciais de produção e webhook de produção do Mercado Pago;
7. executar checklist de `deployment/security-hardening.md`;
8. validar autenticação, compra, webhook, retorno, acesso e integração Moodle/SSO;
9. publicar somente após a bateria final aprovada.

Não executar `database/schema.sql` sobre banco de produção existente.

## Regra de ambientes

Staging utiliza banco, usuário de banco e configurações locais separados da produção.

`config/app.php` centraliza URL base, origens confiáveis e namespace de sessão por ambiente, eliminando overrides manuais de domínio no staging.

O Checkout Pro utiliza a URL base resolvida pela aplicação; qualquer `base_url` legado no arquivo local precisa coincidir com esse ambiente.

Arquivos compactados usados para upload devem ser removidos do document root após extração.
