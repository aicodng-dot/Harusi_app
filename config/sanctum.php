<?php

return [
    // This API uses device bearer tokens. Browser auth remains in the web guard.
    'guard' => [],
    'stateful' => [],
    'routes' => false,
    'expiration' => 1440,
    'token_prefix' => 'kadify_',
];
