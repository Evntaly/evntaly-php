<?php

namespace Evntaly;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class EvntalySDK
{
    /**
     * Base URL for Evntaly API
     */
    private const BASE_URL = "https://app.evntaly.com";

    /**
     * @var string The developer secret for authenticating API requests
     */
    private $developerSecret;

    /**
     * @var string The project token for identifying the project
     */
    private $projectToken;

    /**
     * @var bool Flag to enable or disable event tracking
     */
    private $trackingEnabled = true;

    /**
     * @var Client Guzzle HTTP client instance
     */
    private $client;

    /**
     * Initialize the SDK with a developer secret and project token.
     *
     * @param string $developerSecret The secret key provided by Evntaly
     * @param string $projectToken The token identifying your Evntaly project
     */
    public function __construct(string $developerSecret, string $projectToken)
    {
        $this->developerSecret = $developerSecret;
        $this->projectToken = $projectToken;
        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            'http_errors' => true,
            'timeout' => 10,
        ]);
    }

    /**
     * Check if the API usage limit allows further tracking.
     *
     * @return bool True if tracking is allowed, false if limit is reached or an error occurs
     */
    public function checkLimit(): bool
    {
        $url = "/prod/api/v1/account/check-limits/{$this->developerSecret}";
        $headers = [
            'Content-Type' => 'application/json',
        ];

        try {
            $response = $this->client->get($url, [
                'headers' => $headers
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['limitReached'])) {
                error_log("Unexpected response: " . json_encode($data));
                return false; // Default behavior if key is missing
            }

            return !$data['limitReached']; // Return true if limit is NOT reached
        } catch (Exception | GuzzleException $e) {
            error_log("Error checking limit: " . $e->getMessage());
            return false; // Fails safe (assumes limit is reached)
        }
    }

    /**
     * Track an event by sending it to the Evntaly API.
     *
     * @param array $eventData Associative array of event details (e.g., title, description, sessionID)
     * @return bool True if the event was tracked successfully, false otherwise
     */
    public function track(array $eventData): bool
    {
        if (!$this->trackingEnabled) {
            error_log("Tracking is disabled. Event not sent.");
            return false;
        }

        if (!$this->checkLimit()) {
            error_log("checkLimit returned false. Event not sent.");
            return false;
        }

        $url = "/prod/api/v1/register/event";
        $headers = [
            'Content-Type' => 'application/json',
            'secret' => $this->developerSecret,
            'pat' => $this->projectToken,
        ];

        try {
            $response = $this->client->post($url, [
                'headers' => $headers,
                'json' => $eventData
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            error_log("Track event response: " . json_encode($responseData));
            return true;
        } catch (Exception | GuzzleException $e) {
            error_log("Track event error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Identify a user in the Evntaly system.
     *
     * @param array $userData Associative array of user details (e.g., id, email, full_name)
     * @return bool True if the user was identified successfully, false otherwise
     */
    public function identifyUser(array $userData): bool
    {
        $url = "/prod/api/v1/register/user";
        $headers = [
            'Content-Type' => 'application/json',
            'secret' => $this->developerSecret,
            'pat' => $this->projectToken,
        ];

        $payload = [
            'data' => $userData
        ];

        try {
            $response = $this->client->post($url, [
                'headers' => $headers,
                'json' => $userData
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            error_log("Identify user response: " . json_encode($responseData));
            return true;
        } catch (Exception | GuzzleException $e) {
            error_log("Identify user error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Disable event tracking.
     *
     * @return void
     */
    public function disableTracking(): void
    {
        $this->trackingEnabled = false;
        error_log("Tracking disabled.");
    }

    /**
     * Enable event tracking.
     *
     * @return void
     */
    public function enableTracking(): void
    {
        $this->trackingEnabled = true;
        error_log("Tracking enabled.");
    }
}
