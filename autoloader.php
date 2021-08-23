<?php

spl_autoload_register(
    function (string $className) {
        $baseNamespace = 'IWS\\Referral\\';
        if (strpos($className, $baseNamespace) !== false) {
            $path = str_replace([$baseNamespace, '\\'], ['', '/'], $className);
            require_once __DIR__ . '/' . $path . '.php';
        }
    }
);
