<?php

namespace Evntaly\Webhook;

class WebhookManager
{
    /**
     * @var array Registered webhook handlers
     */
    private array $webhookHandlers = [];

    /**
     * @var string Secret used to validate webhook signatures
     */
    private string $webhookSecret;

    /**
     * Initialize the webhook manager.
     *
     * @param string $webhookSecret Secret for validating webhooks
     */
    public function __construct(string $webhookSecret)
    {
        $this->webhookSecret = $webhookSecret;
    }

    /**
     * Register a handler for a specific webhook event.
     *
     * @param  string   $event   Event name
     * @param  callable $handler Callback function
     * @return self
     */
    public function registerHandler(string $event, callable $handler): self
    {
        if (!isset($this->webhookHandlers[$event])) {
            $this->webhookHandlers[$event] = [];
        }

        $this->webhookHandlers[$event][] = $handler;

        return $this;
    }

    /**
     * Process an incoming webhook.
     *
     * @param  string $payload Raw webhook payload
     * @param  array  $headers Request headers
     * @return bool   Success status
     */
    public function processWebhook(string $payload, array $headers): bool
    {
        // Verify signature
        if (!$this->verifySignature($payload, $headers)) {
            return false;
        }

        // Parse payload
        $data = json_decode($payload, true);
        if (!$data || !isset($data['event'])) {
            return false;
        }

        // Get event type
        $event = $data['event'];

        // Call handlers for this event
        $handlers = $this->webhookHandlers[$event] ?? [];

        // Call handlers for "all" events
        if (isset($this->webhookHandlers['*'])) {
            $handlers = array_merge($handlers, $this->webhookHandlers['*']);
        }

        if (empty($handlers)) {
            // No handlers for this event
            return true;
        }

        // Call all handlers
        foreach ($handlers as $handler) {
            try {
                call_user_func($handler, $data, $event);
            } catch (\Throwable $e) {
                // Log error but continue processing
                error_log('Webhook handler error: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Verify webhook signature.
     *
     * @param  string $payload Raw webhook payload
     * @param  array  $headers Request headers
     * @return bool   Valid signature
     */
    private function verifySignature(string $payload, array $headers): bool
    {
        $signatureHeader = null;

        // Find signature header (case-insensitive)
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-evntaly-signature') {
                $signatureHeader = $value;
                break;
            }
        }

        if (!$signatureHeader) {
            return false;
        }

        // Extract timestamp and signature
        if (!preg_match('/t=([0-9]+),v1=([a-f0-9]+)/', $signatureHeader, $matches)) {
            return false;
        }

        $timestamp = $matches[1];
        $signature = $matches[2];

        // Prevent replay attacks
        if (abs(time() - $timestamp) > 300) { // 5 minute tolerance
            return false;
        }

        // Verify HMAC
        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get all registered webhook handlers.
     *
     * @return array Registered handlers
     */
    public function getRegisteredHandlers(): array
    {
        return $this->webhookHandlers;
    }
}
