# Configuração do formulário de contato — Resend

O endpoint `contato.php` envia as mensagens do formulário para a EVA usando a API do Resend.

## 1. Verifique o domínio no Resend

Adicione `evaglobal.com.br` no painel do Resend e configure os registros DNS solicitados (SPF e DKIM). Aguarde o domínio aparecer como verificado.

## 2. Crie uma API Key

Crie uma chave de produção com permissão apenas de envio (`Sending access`) e, se possível, restrita ao domínio da EVA.

Nunca coloque essa chave em JavaScript, HTML, CSS ou em arquivos versionados no GitHub.

## 3. Configure a chave na hospedagem

O endpoint procura primeiro a variável de ambiente `RESEND_API_KEY`.

Se a hospedagem não disponibilizar uma forma simples de configurar variáveis de ambiente, crie manualmente este arquivo FORA de `public_html`:

`~/eva-private/resend.php`

Conteúdo:

```php
<?php

return [
    'api_key' => 'COLE_AQUI_SUA_CHAVE_RESEND',
    'from' => 'EVA Site <site@evaglobal.com.br>',
    'to' => 'contato@evaglobal.com.br',
];
```

Estrutura esperada na HostGator:

```text
/home/SEU_USUARIO/
├── eva-private/
│   └── resend.php
└── public_html/
    ├── api/
    │   └── contato.php
    ├── contato.html
    ├── css/
    ├── js/
    └── assets/
```

O arquivo com a chave deve permanecer fora de `public_html` e não deve ser enviado ao GitHub.

## 4. Teste

Após publicar os arquivos, envie uma mensagem real pela página `contato.html`.

O fluxo esperado é:

```text
contato.html
→ js/contato.js
→ POST api/contato.php
→ Resend
→ contato@evaglobal.com.br
```

Ao responder o e-mail recebido, o cliente de e-mail deve usar o endereço informado pelo visitante porque o endpoint configura `Reply-To`.

## Segurança implementada

- chave do Resend somente no servidor;
- validação dos dados novamente no backend;
- escape do conteúdo antes de gerar o HTML do e-mail;
- assuntos e perfis aceitos por lista fechada;
- limite de 1000 caracteres na mensagem;
- rate limit de 3 tentativas a cada 10 minutos por IP (armazenado como hash);
- honeypot validado no backend com resposta neutra para bots;
- bloqueio de envios feitos em menos de 3 segundos;
- erros internos do Resend não são expostos ao visitante.
