#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use DouglasGreen\FeedReader\AppContainer;
use DouglasGreen\FeedReader\Controller\ImportController;

$options = getopt('qf');
$quiet = isset($options['q']);

$app = AppContainer::getInstance();
$controller = new ImportController($app);

// CLI always forces import regardless of next_read schedule
$result = $controller->process(true);

if (!$quiet) {
    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $err) {
            error_log($err);
        }
    }
    error_log("Import completed. Added {$result['new']} new items.");
}
