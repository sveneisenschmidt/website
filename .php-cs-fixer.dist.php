<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/www/site', __DIR__ . '/bin', __DIR__ . '/tests'])
    ->exclude(['cache', 'sessions', 'accounts', 'plugins/markdown-field'])
    ->notPath('config/env.php');

return (new PhpCsFixer\Config())
    ->setRules(['@PER-CS2.0' => true])
    ->setFinder($finder);
