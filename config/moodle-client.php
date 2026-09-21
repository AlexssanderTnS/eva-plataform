<?php

declare(strict_types=1);

$config = require __DIR__ . '/moodle.php';

final class EvaMoodleClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'A extensão cURL do PHP não está disponível.'
            );
        }
    }

    public function call(string $function, array $parameters = []): mixed
    {
        $function = trim($function);

        if (
            $function === '' ||
            !preg_match('/^[a-z][a-z0-9_]+$/', $function)
        ) {
            throw new InvalidArgumentException(
                'Função de Web Service do Moodle inválida.'
            );
        }

        $payload = array_merge(
            $parameters,
            [
                'wstoken' => $this->token,
                'wsfunction' => $function,
                'moodlewsrestformat' => 'json',
            ]
        );

        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException(
                'Não foi possível inicializar a conexão com o Moodle.'
            );
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->baseUrl . '/webservice/rest/server.php',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(
                $payload,
                '',
                '&',
                PHP_QUERY_RFC3986
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $responseBody = curl_exec($curl);

        if ($responseBody === false) {
            $errorNumber = curl_errno($curl);
            $errorMessage = curl_error($curl);
            curl_close($curl);

            error_log(sprintf(
                'Moodle HTTP error (%d): %s',
                $errorNumber,
                $errorMessage
            ));

            throw new RuntimeException(
                'Não foi possível comunicar com o Moodle.'
            );
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status !== 200) {
            error_log(sprintf(
                'Moodle retornou HTTP %d para a função %s.',
                $status,
                $function
            ));

            throw new RuntimeException(
                'O Moodle retornou uma resposta HTTP inesperada.'
            );
        }

        try {
            $decoded = json_decode(
                $responseBody,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            error_log(sprintf(
                'Moodle retornou JSON inválido para a função %s.',
                $function
            ));

            throw new RuntimeException(
                'Resposta inválida recebida do Moodle.'
            );
        }

        if (
            is_array($decoded) &&
            isset($decoded['exception'])
        ) {
            $errorCode = trim((string) ($decoded['errorcode'] ?? 'unknown'));
            $message = trim((string) ($decoded['message'] ?? ''));

            error_log(sprintf(
                'Moodle WS error em %s [%s]: %s',
                $function,
                $errorCode,
                $message
            ));

            throw new RuntimeException(
                'O Moodle recusou a operação solicitada.'
            );
        }

        return $decoded;
    }
}

return new EvaMoodleClient(
    $config['base_url'],
    $config['token']
);
