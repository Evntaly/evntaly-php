<?php

namespace Evntaly\Sampling;

class SamplingManager
{
    /**
     * @var float Default sampling rate (1.0 = 100%)
     */
    private float $samplingRate = 1.0;

    /**
     * @var array Events that should always be tracked regardless of sampling
     */
    private array $priorityEvents = [];

    /**
     * @var array Event types with specific sampling rates
     */
    private array $typeRates = [];

    /**
     * @var array Cache of sampling decisions to ensure consistency
     */
    private array $decisionCache = [];

    /**
     * Initialize the sampling manager.
     *
     * @param array $config Sampling configuration
     */
    public function __construct(array $config = [])
    {
        if (isset($config['rate'])) {
            $this->samplingRate = max(0, min(1, (float)$config['rate']));
        }

        if (isset($config['priorityEvents']) && is_array($config['priorityEvents'])) {
            $this->priorityEvents = $config['priorityEvents'];
        }

        if (isset($config['typeRates']) && is_array($config['typeRates'])) {
            $this->typeRates = $config['typeRates'];
        }
    }

    /**
     * Determine if an event should be sampled.
     *
     * @param  array $event The event data
     * @return bool  True if the event should be tracked
     */
    public function shouldSample(array $event): bool
    {
        // Generate consistent ID for event
        $eventId = $this->getEventId($event);

        // Return cached decision if available
        if (isset($this->decisionCache[$eventId])) {
            return $this->decisionCache[$eventId];
        }

        // Priority events are always sampled
        if ($this->isHighPriorityEvent($event)) {
            $this->decisionCache[$eventId] = true;
            return true;
        }

        // Get applicable sampling rate
        $rate = $this->getSamplingRateForEvent($event);

        // Determine sampling decision
        $decision = (mt_rand(1, 1000000) / 1000000) <= $rate;

        // Cache the decision
        $this->decisionCache[$eventId] = $decision;

        return $decision;
    }

    /**
     * Check if an event is high-priority and should bypass sampling.
     *
     * @param  array $event The event data
     * @return bool
     */
    private function isHighPriorityEvent(array $event): bool
    {
        // Check for priority by title
        if (isset($event['title']) && in_array($event['title'], $this->priorityEvents)) {
            return true;
        }

        // Check for priority by type
        if (isset($event['type']) && in_array($event['type'], $this->priorityEvents)) {
            return true;
        }

        // Check for priority by tags
        if (isset($event['tags']) && is_array($event['tags'])) {
            foreach ($event['tags'] as $tag) {
                if (in_array($tag, $this->priorityEvents)) {
                    return true;
                }
            }
        }

        // Check for "error" or "exception" in title for smart sampling
        if (isset($event['title'])) {
            $title = strtolower($event['title']);
            if (
                strpos($title, 'error') !== false ||
                strpos($title, 'exception') !== false ||
                strpos($title, 'fail') !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the appropriate sampling rate for an event.
     *
     * @param  array $event The event data
     * @return float Sampling rate from 0.0 to 1.0
     */
    private function getSamplingRateForEvent(array $event): float
    {
        // Check for type-specific sampling rate
        if (isset($event['type']) && isset($this->typeRates[$event['type']])) {
            return max(0, min(1, (float)$this->typeRates[$event['type']]));
        }

        return $this->samplingRate;
    }

    /**
     * Generate a consistent ID for an event.
     *
     * @param  array  $event The event data
     * @return string
     */
    private function getEventId(array $event): string
    {
        // Use existing ID if available
        if (isset($event['id'])) {
            return (string)$event['id'];
        }

        // Generate consistent hash based on event content
        $data = [
            'title' => $event['title'] ?? '',
            'timestamp' => $event['timestamp'] ?? time(),
        ];

        if (isset($event['data']['user_id'])) {
            $data['user_id'] = $event['data']['user_id'];
        }

        return md5(json_encode($data));
    }

    /**
     * Set the default sampling rate.
     *
     * @param  float $rate Sampling rate from 0.0 to 1.0
     * @return self
     */
    public function setSamplingRate(float $rate): self
    {
        $this->samplingRate = max(0, min(1, $rate));
        return $this;
    }

    /**
     * Set priority events that should bypass sampling.
     *
     * @param  array $events List of event titles, types, or tags to prioritize
     * @return self
     */
    public function setPriorityEvents(array $events): self
    {
        $this->priorityEvents = $events;
        return $this;
    }

    /**
     * Set specific sampling rates for different event types.
     *
     * @param  array $typeRates Associative array of event types and their rates
     * @return self
     */
    public function setTypeRates(array $typeRates): self
    {
        $this->typeRates = $typeRates;
        return $this;
    }

    public function track($event, $marker = null)
    {
        // Convert string event to array if needed (existing code)

        // Apply sampling if enabled - Added null check
        if ($this->samplingManager !== null && !$this->shouldSampleEvent($event)) {
            return true; // Consider sampled-out events as successfully tracked
        }

        // Existing track implementation
    }
}
