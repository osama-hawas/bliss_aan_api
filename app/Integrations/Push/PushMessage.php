<?php

namespace App\Integrations\Push;

/**
 * One push to one device token. `data` holds only notification_id, event, target_type, target_id (D141).
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $token,
        public string $title,
        public string $body,
        public array $data,
        public string $collapseKey,
    ) {}
}
