<?php

namespace App\Services\Chat;

use RuntimeException;

/**
 * Internal signal: a send with the same client_uuid committed first. Never rendered.
 */
class DuplicateSend extends RuntimeException {}
