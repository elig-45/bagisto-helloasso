<?php

$root = dirname(__DIR__).'/src/Resources/lang';
$locales = ['tr', 'bn', 'pl', 'ro', 'fr', 'hi_IN', 'id', 'ar', 'fa', 'de', 'uk', 'ru', 'es', 'nl', 'he', 'zh_CN', 'ca', 'ja', 'it', 'sin', 'pt_BR', 'en'];

$flatten = function (array $items, string $prefix = '') use (&$flatten): array {
    $keys = [];

    foreach ($items as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        $keys[] = $path;

        if (is_array($value)) {
            $keys = [...$keys, ...$flatten($value, $path)];
        }
    }

    sort($keys);

    return $keys;
};

$reference = $flatten(require $root.'/en/app.php');
$failures = [];

foreach ($locales as $locale) {
    $path = $root.'/'.$locale.'/app.php';

    if (! is_file($path)) {
        $failures[] = $locale.': translation file is missing';

        continue;
    }

    $keys = $flatten(require $path);

    if ($keys !== $reference) {
        $failures[] = $locale.': translation keys differ from en';
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
    exit(1);
}

echo 'Translation keys are consistent across '.count($locales).' locales.'.PHP_EOL;
