<?php
spl_autoload_register(function (string $class): void {
    $base = dirname(__DIR__);
    foreach ([
        ['ADT\\DoctrineAnonymization\\Tests\\Fixtures\\', '/tests/unit/Fixtures/'],
        ['ADT\\DoctrineAnonymization\\', '/src/'],
    ] as [$prefix, $dir]) {
        if (str_starts_with($class, $prefix)) {
            $f = $base . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($f)) { require $f; }
            return;
        }
    }
    if ($class === 'BaseTest') { require $base . '/tests/unit/BaseTest.php'; }
});
