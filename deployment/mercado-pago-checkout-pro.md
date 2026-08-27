# Mercado Pago Checkout Pro — integração EVA

## Arquitetura adotada

A EVA utiliza **Mercado Pago Checkout Pro via Preferences API**.

Fluxo:

`usuário autenticado → curso → pedido EVA → Preference Mercado Pago → Checkout Pro → retorno/webhook → sincronização → liberação do curso`

A EVA não recebe número de cartão, CVV ou token de cartão. Esses dados são informados e processados no ambiente do Mercado Pago.

## Arquivos principais

- `api/orders/create.php` — cria/reutiliza o pedido interno e cria/reutiliza a Preference.
- `api/orders/status.php` — consulta o pedido do usuário e sincroniza o pagamento retornado quando há `payment_id`.
- `api/payments/webhook.php` — recebe o evento `payment`, valida `x-signature` e consulta `/v1/payments/{id}`.
- `config/mercadopago.php` — configuração por ambiente.
- `config/mercadopago-client.php` — cliente HTTP da API Mercado Pago.
- `config/mercadopago-commerce.php` — validação do webhook e sincronização comercial.
- `js/recursos.js` — inicia o Checkout Pro pelo botão Comprar curso.
- `pagamento.html` / `js/pagamento.js` — retorno visual e consulta segura do pedido.

## Configuração local

`config/mercadopago.local.php` não deve ser versionado.

Use `config/mercadopago.example.php` como referência.

### Staging

```php
<?php
return [
    'environment' => 'test',
    'access_token' => 'APP_USR-...',
    'webhook_secret' => '...',
    'currency' => 'BRL',
];
```

A URL base é obtida de `config/app.php`. Um `base_url` legado ainda pode existir no arquivo local, mas, se informado, precisa corresponder exatamente ao ambiente detectado.

## Banco existente

Executar, em ordem, após a migration comercial de 2026-08-16:

1. `database/migrations/2026-08-19-checkout-pro.sql`;
2. `database/migrations/2026-08-27-commerce-hardening.sql`.

A primeira adapta pedidos e pagamentos ao Checkout Pro. A segunda adiciona o estado `processing` aos eventos de webhook para permitir aquisição atômica do processamento e retries seguros.

Não executar `database/schema.sql` sobre um banco já existente.

## Webhook

Cada Preference também envia explicitamente `notification_url` para o endpoint do ambiente atual. No painel Mercado Pago Developers, mantenha o evento **Pagamentos** (`payment`) configurado para:

- teste: `https://staging.evaglobal.com.br/api/payments/webhook.php`
- produção: `https://evaglobal.com.br/api/payments/webhook.php`

Copie a chave secreta gerada para `webhook_secret` no arquivo local do ambiente correspondente.

O endpoint não confia no payload recebido. Após validar `x-signature`, consulta o pagamento diretamente na API do Mercado Pago e compara:

- `external_reference`;
- valor;
- moeda;
- usuário/pedido quando a sincronização é iniciada pelo retorno do navegador.

## Teste de Checkout Pro em staging

1. aplicar as migrations `2026-08-19-checkout-pro.sql` e `2026-08-27-commerce-hardening.sql`, nessa ordem;
2. confirmar `mercadopago.local.php` com credenciais de teste;
3. entrar em uma conta EVA de staging com e-mail confirmado;
4. abrir `recursos.html`;
5. escolher um curso e clicar em **Comprar curso**;
6. confirmar que o navegador é redirecionado ao Checkout Pro;
7. realizar a compra com a conta Comprador de teste e os meios de pagamento de teste do Mercado Pago;
8. confirmar retorno para `pagamento.html`;
9. validar as tabelas `orders`, `payments` e `course_access`.

Pagamentos criados com credenciais de teste podem não disparar automaticamente o webhook normal. Para validar o receptor, utilize o simulador de Webhooks do painel e, de preferência, informe o ID de um pagamento de teste existente para que a consulta `/v1/payments/{id}` também seja exercitada.

## Regras de segurança

- Access Token e webhook secret nunca entram no Git ou no JavaScript.
- preço e moeda vêm do banco, nunca do navegador.
- `external_reference` é aleatória e não expõe `orders.id`.
- retorno `status=approved` da URL não libera curso.
- somente a confirmação consultada na API do Mercado Pago altera o pedido.
- o webhook é idempotente por `payment_webhook_events`.
- um pagamento aprovado cria/atualiza `course_access` como `pending`; a ativação definitiva será concluída pelo bloco Moodle/SSO.


## Hardening adicional

- `sandbox_init_point` é aceito somente no ambiente de teste.
- `init_point` é aceito somente em produção.
- o ID retornado por `/v1/payments/{id}` deve ser exatamente o ID consultado.
- reembolsos revogam apenas o acesso originado pelo mesmo `order_id`, evitando que um evento antigo revogue uma compra posterior.
- quando há múltiplas tentativas de pagamento, a API de status prioriza o pagamento coerente com o estado atual do pedido.
