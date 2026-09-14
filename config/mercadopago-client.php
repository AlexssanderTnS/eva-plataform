<?php

declare(strict_types=1);

$config = require __DIR__ . '/mercadopago.php';

final class EvaMercadoPagoClient
{
    private const BASE_URL = 'https://api.mercadopago.com';

    public function __construct(
        private readonly string $accessToken
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'A extensão cURL do PHP não está disponível.'
            );
        }
    }

    
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $idempotencyKey = null
    ): array {
        $method = strtoupper(trim($method));

        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException(
                'Método HTTP não suportado.'
            );
        }

        if (
            $path === '' ||
            $path[0] !== '/' ||
            str_contains($path, '://')
        ) {
            throw new InvalidArgumentException(
                'Caminho inválido para a API do Mercado Pago.'
            );
        }

        $url = self::BASE_URL . $path;

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/json',
        ];

        $payload = null;

        if ($body !== null) {
            $payload = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );

            $headers[] = 'Content-Type: application/json';
        }

        if ($idempotencyKey !== null) {
            $idempotencyKey = trim($idempotencyKey);

            if ($idempotencyKey === '') {
                throw new InvalidArgumentException(
                    'Chave de idempotência inválida.'
                );
            }

            $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        }

        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException(
                'Não foi possível inicializar a conexão HTTP.'
            );
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,

            
            CURLOPT_FOLLOWLOCATION => false,

            
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,

            
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($curl, $options);

        $responseBody = curl_exec($curl);

        if ($responseBody === false) {
            $curlError = curl_error($curl);
            $curlErrorNumber = curl_errno($curl);

            curl_close($curl);

            
            error_log(sprintf(
                'Mercado Pago HTTP error (%d): %s',
                $curlErrorNumber,
                $curlError
            ));

            throw new RuntimeException(
                'Não foi possível comunicar com o Mercado Pago.'
            );
        }

        $status = (int) curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE
        );

        curl_close($curl);

        $decoded = null;

        if ($responseBody !== '') {
            try {
                $decoded = json_decode(
                    $responseBody,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                error_log(sprintf(
                    'Mercado Pago retornou JSON inválido. HTTP %d.',
                    $status
                ));

                throw new RuntimeException(
                    'Resposta inválida recebida do Mercado Pago.'
                );
            }
        }

        return [
            'status' => $status,
            'data' => $decoded,
        ];
    }
}

return new EvaMercadoPagoClient(
    $config['access_token']
);
