<?php

declare(strict_types=1);

// Hostinger Git deployments expose the repository root as the document root.
// Keep the application entry point inside /public and dispatch requests there.
require __DIR__ . '/public/index.php';
