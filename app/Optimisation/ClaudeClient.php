<?php

declare(strict_types=1);

namespace BMT\Optimisation;

use RuntimeException;

/**
 * Thin wrapper around the Anthropic Messages API. No SDK dependency —
 * just cURL, since this is the only place in the codebase that needs it.
 */
final class ClaudeClient
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require dirname(__DIR__, 2) . '/config/claude_optimisation.php';
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['enabled']) && $this->config['api_key'] !== '';
    }

    /**
     * Sends a single-turn message with the given system prompt and returns
     * the raw text of Claude's reply. Throws on any transport/API error —
     * callers should treat a failure here as "skip today's Claude pass",
     * never as a reason to fall back to inventing data.
     */
    public function send(string $system, string $userMessage): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Claude optimisation is not enabled or ANTHROPIC_API_KEY is not set.');
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => (int) ($this->config['timeout_seconds'] ?? 30),
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $this->config['api_key'],
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->config['model'],
                'max_tokens' => (int) ($this->config['max_tokens'] ?? 2000),
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $userMessage]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Could not reach the Claude API: ' . $curlError);
        }
        if ($httpCode !== 200) {
            throw new RuntimeException('Claude API returned HTTP ' . $httpCode . ': ' . mb_substr($raw, 0, 500));
        }

        $data = json_decode($raw, true);
        $text = $data['content'][0]['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new RuntimeException('Claude API response had no text content.');
        }

        return $text;
    }
}
