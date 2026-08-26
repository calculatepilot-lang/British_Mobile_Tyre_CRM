<?php

declare(strict_types=1);

namespace App\Notifications;

final class WhatsAppClient
{
    public function __construct(
        private string $phoneNumberId,
        private string $accessToken,
        private string $apiVersion = 'v22.0'
    ) {}

    public function sendTemplate(string $recipient, string $templateName, string $languageCode, array $bodyValues): array
    {
        if ($this->phoneNumberId === '' || $this->accessToken === '') {
            throw new \RuntimeException('WhatsApp API is not configured.');
        }

        $components = [[
            'type' => 'body',
            'parameters' => array_map(
                static fn ($value) => ['type' => 'text', 'text' => (string) $value],
                $bodyValues
            ),
        ]];

        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
                'components' => $components,
            ],
        ], JSON_THROW_ON_ERROR);

        $url = sprintf('https://graph.facebook.com/%s/%s/messages', $this->apiVersion, $this->phoneNumberId);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('WhatsApp API request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('WhatsApp API error: ' . $response);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
