# Integração EVA com Moodle via External Database e HTTPS

Estado: proposta de homologação. Não ativar autenticação externa em produção até a equipe Moodle validar a solução.

## Arquitetura
- EVA conserva o MySQL na HostGator.
- A EVA envia somente contas ativas e verificadas da visão eva_moodle para uma API HTTPS administrada pela equipe Moodle.
- O receptor grava os dados em um banco isolado no ambiente do Moodle, com conta de leitura exclusiva para o auth_db. O Moodle consulta essa cópia local.
- Não utilizar a conexão MySQL remota atual da HostGator: falta TLS e a permissão existente dá leitura de todo o banco.
- Os hashes bcrypt são dados sensíveis. Não incluir senhas em texto puro nem registrar hashes nos logs.

## Contrato a confirmar com a equipe Moodle
POST HTTPS com JSON contendo versão, ID aleatório de snapshot, timestamp de geração, expiração e lista completa de usuários (username, password hash, email, firstname, lastname, idnumber). Assinar corpo com HMAC-SHA256 e segredo compartilhado fora do webroot; exigir certificado TLS válido, controle antirreplay, verificação do ID e limites de tamanho. Receptor grava snapshot completo em transação e responde apenas depois do commit. Uma conta removida do snapshot perde o acesso. Implementar expiração da cópia para negar autenticação se a sincronização parar. Testar também envio imediato quando houver bloqueio ou troca de senha.

A configuração auth_db deve consultar uma tabela ou visão local somente leitura e mapear username, password, email, firstname, lastname e idnumber. Verificar na versão instalada a opção Salted Crypt com hashes bcrypt. Desabilitar atualização dos campos no banco externo pelo Moodle.

## Migração
Inventariar usuários existentes por idnumber eva:<id> e comparar users.moodle_user_id, email e username. Preservar o mesmo ID Moodle e todas as matrículas. Nunca vincular ou criar conta só pela coincidência do email. A equipe Moodle precisa confirmar como alterar auth de manual para db e username para email na versão instalada, bem como a compatibilidade com auth_userkey_request_login_url para manter o SSO EVA. Testar primeiro com uma conta de homologação, incluindo o login direto.

## Informações pendentes da equipe Moodle
1. Disponibilidade do banco isolado e do receptor HTTPS.
2. URL e contrato do endpoint de recebimento.
3. Compatibilidade do bcrypt e procedimento de migração dos usuários legados.
4. Garantia de invalidação por bloqueio, troca de senha e interrupção da sincronização.
5. Compatibilidade do plugin User Key com contas auth=db.
6. Ambiente e data para testes conjuntos.

Não distribuir credenciais em tickets ou mensagens. Não ativar auth_mode=db nem o cron de sincronização antes de homologar todas as salvaguardas.

Documentação Moodle: https://docs.moodle.org/500/en/External_database_authentication
