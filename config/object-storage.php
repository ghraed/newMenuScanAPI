<?php

return [
    'disk' => env('OBJECT_STORAGE_DISK', 'b2'),
    'signed_url_ttl_minutes' => (int) env('OBJECT_STORAGE_SIGNED_URL_TTL', 15),
];
