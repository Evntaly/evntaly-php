<?php

namespace Evntaly\Realtime;

use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

class WebSocketClient
{
    /**
     * @var string WebSocket server URL
     */
    private string $serverUrl;

    /**
     * @var array Authentication credentials
     */
    private array $credentials;

    /**
     * @var WebSocket|null Active connection
     */
    private ?WebSocket $connection = null;

    /**
     * @var LoopInterface Event loop
     */
    private LoopInterface $loop;

    /**
     * @var array Message handlers by type
     */
    private array $messageHandlers = [];

    /**
     * @var bool Whether the client is connected
     */
    private bool $isConnected = false;

    /**
     * @var array Connection event handlers
     */
    private array $eventHandlers = [
        'connect' => [],
        'disconnect' => [],
        'error' => [],
    ];

    /**
     * Initialize the WebSocket client.
     *
     * @param string             $serverUrl   WebSocket server URL
     * @param array              $credentials Authentication credentials
     * @param LoopInterface|null $loop        Event loop (optional)
     */
    public function __construct(string $serverUrl, array $credentials, ?LoopInterface $loop = null)
    {
        if (!class_exists('Ratchet\Client\Connector')) {
            throw new \RuntimeException('Ratchet/Pawl is required for WebSocket functionality. Install it with: composer require ratchet/pawl');
        }

        $this->serverUrl = $serverUrl;
        $this->credentials = $credentials;
        $this->loop = $loop ?? Loop::get();
    }

    /**
     * Connect to the WebSocket server.
     *
     * @return PromiseInterface Promise resolving when connected
     */
    public function connect(): PromiseInterface
    {
        $connector = new Connector($this->loop);

        return $connector($this->serverUrl)->then(
            function (WebSocket $conn) {
                $this->connection = $conn;
                $this->isConnected = true;

                // Set up message handler
                $conn->on('message', function ($msg) {
                    $this->handleMessage($msg);
                });

                // Set up close handler
                $conn->on('close', function ($code = null, $reason = null) {
                    $this->isConnected = false;
                    $this->connection = null;

                    foreach ($this->eventHandlers['disconnect'] as $handler) {
                        call_user_func($handler, $code, $reason);
                    }
                });

                // Authenticate
                $this->sendMessage('auth', $this->credentials);

                // Call connect handlers
                foreach ($this->eventHandlers['connect'] as $handler) {
                    call_user_func($handler, $conn);
                }

                return $conn;
            },
            function (\Exception $e) {
                $this->isConnected = false;

                foreach ($this->eventHandlers['error'] as $handler) {
                    call_user_func($handler, $e);
                }

                throw $e;
            }
        );
    }

    /**
     * Register a handler for a specific message type.
     *
     * @param  string   $messageType Message type
     * @param  callable $handler     Handler function
     * @return self
     */
    public function on(string $messageType, callable $handler): self
    {
        if (!isset($this->messageHandlers[$messageType])) {
            $this->messageHandlers[$messageType] = [];
        }

        $this->messageHandlers[$messageType][] = $handler;

        return $this;
    }

    /**
     * Register a connection event handler.
     *
     * @param  string   $event   Event name (connect, disconnect, error)
     * @param  callable $handler Handler function
     * @return self
     */
    public function onConnection(string $event, callable $handler): self
    {
        if (isset($this->eventHandlers[$event])) {
            $this->eventHandlers[$event][] = $handler;
        }

        return $this;
    }

    /**
     * Send a message to the server.
     *
     * @param  string               $type Message type
     * @param  array<string, mixed> $data Message data
     * @return bool                 Success status
     */
    public function sendMessage(string $type, array $data = []): bool
    {
        if (!$this->isConnected || !$this->connection) {
            return false;
        }

        $message = json_encode([
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
        ]);

        $this->connection->send($message);

        return true;
    }

    /**
     * Handle incoming messages.
     *
     * @param string $rawMessage Raw message
     */
    private function handleMessage(string $rawMessage): void
    {
        $message = json_decode($rawMessage, true);

        if (!$message || !isset($message['type'])) {
            return;
        }

        $type = $message['type'];
        $handlers = $this->messageHandlers[$type] ?? [];

        // Also call handlers for all messages
        if (isset($this->messageHandlers['*'])) {
            $handlers = array_merge($handlers, $this->messageHandlers['*']);
        }

        foreach ($handlers as $handler) {
            call_user_func($handler, $message['data'] ?? [], $type, $message);
        }
    }

    /**
     * Subscribe to an event channel.
     *
     * @param  string            $channel Channel to subscribe to
     * @return bool              True if subscription request was sent successfully
     * @throws \RuntimeException When not connected to server
     */
    public function subscribe(string $channel): bool
    {
        if (!$this->isConnected) {
            throw new \RuntimeException('Cannot subscribe: Not connected to server');
        }

        return $this->sendMessage('subscribe', ['channel' => $channel]);
    }

    /**
     * Unsubscribe from an event channel.
     *
     * @param  string $channel Channel to unsubscribe from
     * @return bool   Success status
     */
    public function unsubscribe(string $channel): bool
    {
        return $this->sendMessage('unsubscribe', ['channel' => $channel]);
    }

    /**
     * Close the connection.
     */
    public function disconnect(): void
    {
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
            $this->isConnected = false;
        }
    }

    /**
     * Check if client is connected.
     *
     * @return bool Connection status
     */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    /**
     * Subscribe to a realtime event channel.
     *
     * @param  string            $channel Channel name
     * @param  callable          $handler Event handler
     * @return bool              Success status
     * @throws \RuntimeException When realtime client is not initialized
     */
    public function subscribeToChannel(string $channel, callable $handler): bool
    {
        if (!$this->connection) {
            throw new \RuntimeException('Realtime client not initialized. Set realtime.enabled in options.');
        }

        if (!class_exists('Ratchet\Client\Connector')) {
            throw new \RuntimeException('Ratchet/Pawl is required for WebSocket functionality. Install it with: composer require ratchet/pawl');
        }

        $this->connection->on($channel, $handler);
        return $this->connection->subscribe($channel);
    }

    /**
     * Reconnect to the WebSocket server.
     *
     * @param  int              $maxAttempts Maximum number of reconnection attempts
     * @return PromiseInterface Promise resolving when connected
     */
    public function reconnect(int $maxAttempts = 3): PromiseInterface
    {
        $attempt = 0;

        $tryConnect = function () use (&$attempt, $maxAttempts, &$tryConnect) {
            $attempt++;
            return $this->connect()->otherwise(function ($error) use ($attempt, $maxAttempts, $tryConnect) {
                if ($attempt < $maxAttempts) {
                    return $tryConnect();
                }
                throw $error;
            });
        };

        return $tryConnect();
    }
}
