<?php

declare(strict_types=1);

use Symfony\Component\Finder\SplFileInfo as FinderSplFileInfo;

$files_in_bin = PhpCsFixer\Finder::create()
  ->files()
  // Scan files without PHP extension too.
  // https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/blob/fb7f3523d563406554a3e739ecc98c25b7943867/src/Finder.php#L29-L33
  ->name('/.*/')
  ->in([__DIR__ . '/bin']);

$bin_file_names = array_map(static fn (FinderSplFileInfo $file): string => $file->getFilename(), iterator_to_array($files_in_bin));

$finder = PhpCsFixer\Finder::create()
  ->files()
  ->ignoreDotFiles(false)
  ->ignoreVCS(true)
  ->in([__DIR__])
  ->name(array_merge(['*.php'], $bin_file_names))
  ->notPath('*/vendor/*');

$config = new PhpCsFixer\Config();
$config->setRiskyAllowed(true)
    ->setRules([
        '@PSR2' => true,
        '@Symfony' => true,
        'array_syntax' => ['syntax' => 'short'],
        'class_definition' => ['single_line' => false, 'single_item_single_line' => true],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'ordered_class_elements' => true,
        'ordered_imports' => true,
        'phpdoc_align' => false,
        'phpdoc_annotation_without_dot' => false,
        'phpdoc_indent' => false,
        'phpdoc_inline_tag_normalizer' => false,
        'phpdoc_order' => true,
        'single_blank_line_at_eof' => true,
        'self_accessor' => false,
        'void_return' => true,
    ])
    ->setFinder($finder);

return $config;
