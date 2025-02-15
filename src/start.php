#!/usr/bin/env php
<?php

use App\Application;

chdir(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';
Application::run();
