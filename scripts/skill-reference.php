<?php

/*
 * Writes resources/boost/skills/laravel-uniexporter/references/api.md: every public method an export
 * author calls, with its signature and the first line of its docblock, read from the source by
 * reflection. Run it after changing the public API:
 *
 *     php scripts/skill-reference.php
 *
 * Getters and internals used by the drivers (get*, is*, has*, should*, forFormat()...) are left out.
 */

$autoload = getenv('UNIEXPORTER_AUTOLOAD')
    ?: (is_file(dirname(__DIR__).'/vendor/autoload.php') ? dirname(__DIR__).'/vendor/autoload.php' : dirname(__DIR__, 4).'/vendor/autoload.php');

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4('Wovosoft\\LaravelUniexporter\\', dirname(__DIR__).'/src/', true);

use Wovosoft\LaravelUniexporter as U;

/**
 * @var array<string, array{0: class-string, 1: string}> section title => [class, intro]
 */
$sections = [
    'Document'                => [U\Document::class, '`Document::make()`, or extend it and override `settings()`, `schema()`, `styles()`. Page settings called on a document are forwarded to `Settings` (see below).'],
    'Content methods'         => [U\Components\Concerns\BuildsContent::class, 'On `Document` and `Group`. Each appends a component and returns the container; the closure receives the new component.'],
    'PendingExport'           => [U\PendingExport::class, 'Returned by `$document->data($data)`.'],
    'ExportedFile'            => [U\ExportedFile::class, 'A rendered temporary file. `Responsable` (download).'],
    'ExportsByFormat (trait)' => [U\Concerns\ExportsByFormat::class, 'The using class implements `document($request, string $format): PendingExport`.'],
    'Settings'                => [U\Settings::class, 'Page setup, in `Document::settings()`, `configure()` or chained on the document. Lengths in millimetres.'],
    'Component (every component)' => [U\Components\Component::class, 'Plus the style methods below.'],
    'Style methods (every component, Run, Style)' => [U\Components\Concerns\HasStyle::class, 'Applied in order: default style, named style, inline style; children of a Group inherit text properties.'],
    'Heading'                 => [U\Components\Heading::class, '`Heading::make($text = \'\', $level = 1)`'],
    'Text'                    => [U\Components\Text::class, '`Text::make($text = \'\')`; `\n` starts a new line.'],
    'Run'                     => [U\Components\Run::class, '`Run::make($text)`: a styled piece of a Text; has the style methods and:'],
    'Link'                    => [U\Components\Link::class, '`Link::make($text, $url)`'],
    'Image'                   => [U\Components\Image::class, '`Image::make($source)`: a path, a `{placeholder}` or raw bytes; sizes in mm.'],
    'ListBlock'               => [U\Components\ListBlock::class, '`ListBlock::make($items = [])`; items are strings or `[\'text\' => ..., \'items\' => [...]]`.'],
    'KeyValue'                => [U\Components\KeyValue::class, '`KeyValue::make([\'Label\' => \'{value}\'])`'],
    'Table'                   => [U\Components\Table::class, '`Table::make($rows)`: a data path or an iterable of arrays/objects/models.'],
    'Column'                  => [U\Components\Column::class, '`Column::make($key = \'\')`: `$key` is a dot path into the row (`data_get`).'],
    'HeaderCell'              => [U\Components\HeaderCell::class, '`HeaderCell::make($text)`, in `Table::headerRows()` / `headerRow()`.'],
    'Columns'                 => [U\Components\Columns::class, '`Columns::make($blocks = [])`: blocks side by side; each block is a Group, a component or a list.'],
    'Group'                   => [U\Components\Group::class, '`Group::make($children = [])`; also has the content methods.'],
    'Spacer, Divider, PageBreak, Sheet, Native' => [null, '`Spacer::make($mm = 5)`, `Divider::make()` (style it with `border()`), `PageBreak::make()`, `Sheet::make($name)` (new worksheet in XLSX, new page elsewhere), `Native::make()->for($format, $renderer)` (pdf: `fn (Mpdf $mpdf, RenderContext $context)`, docx: `fn (AbstractContainer $container, RenderContext $context)`, xlsx: `fn (Writer $writer, RenderContext $context)`, html: `fn (RenderContext $context): string`).'],
    'Style'                   => [U\Style\Style::class, '`Style::make()`; the same fluent properties as the style methods, for `headerStyle()`, `totalStyle()`, `rowStyleUsing()`, named styles.'],
    'Border'                  => [U\Style\Border::class, '`new Border($width = 0.5 /* pt */, $color = \'000000\', BorderLine $line = BorderLine::Solid)`'],
    'Margins'                 => [U\Style\Margins::class, 'Also accepted as `margins($top, $right, $bottom, $left)` in CSS shorthand order.'],
];

$enums = [U\Style\Paper::class, U\Style\Orientation::class, U\Style\Align::class, U\Style\VerticalAlign::class, U\Style\BorderLine::class];

$skipped = '/^(get|is|has|should)[A-Z]|^(forFormat|defaultStyle|valueFor|cellStyleFor|totalValue|isVisibleFor|resolveSettings|getChildren|schema|settings|styles|__construct|__call|toArray|getWidthPercentages|contentWidth|merge|toCss|resolve|toResponse|inheritable|key)$/';

$typeName = static function (?ReflectionType $type): string {
    if ($type === null) {
        return '';
    }

    $name = (string) $type;

    return preg_replace('/(?:\\\\?[A-Za-z_]+\\\\)+([A-Za-z_]+)/', '$1', $name) ?? $name;
};

$signature = static function (ReflectionMethod $method) use ($typeName): string {
    $parameters = array_map(static function (ReflectionParameter $parameter) use ($typeName): string {
        $text = trim($typeName($parameter->getType()).' '.($parameter->isVariadic() ? '...' : '').'$'.$parameter->getName());

        if ($parameter->isDefaultValueAvailable()) {
            $default = $parameter->isDefaultValueConstant()
                ? preg_replace('/^.*\\\\/', '', (string) $parameter->getDefaultValueConstantName())
                : var_export($parameter->getDefaultValue(), true);
            $default = str_replace(["array (\n)", 'NULL', 'false', 'true', "\n"], ['[]', 'null', 'false', 'true', ''], (string) $default);
            $text .= ' = '.$default;
        }

        return $text;
    }, $method->getParameters());

    return ($method->isStatic() ? '::' : '').$method->getName().'('.implode(', ', $parameters).')';
};

$summary = static function (ReflectionMethod $method): string {
    $doc = (string) $method->getDocComment();

    $paragraph = [];

    foreach (preg_split('/\R/', $doc) ?: [] as $line) {
        $line = trim(ltrim(trim($line), '/*'));

        if ($line === '' || str_starts_with($line, '@')) {
            if ($paragraph !== []) {
                break;
            }

            continue;
        }

        $paragraph[] = $line;
    }

    $text = rtrim(implode(' ', $paragraph), ':.');

    return $text === '' ? '' : $text.'.';
};

$out = [];
$out[] = '# laravel-uniexporter API reference';
$out[] = '';
$out[] = 'Generated from the source by `scripts/skill-reference.php`; do not edit by hand. Getters and driver internals are left out.';
$out[] = 'Methods return the object (fluent) unless noted. `{path}` placeholders in any text are filled from the data with dot notation.';
$out[] = '';

foreach ($sections as $title => [$class, $intro]) {
    $out[] = '## '.$title;
    $out[] = '';
    $out[] = $intro;
    $out[] = '';

    if ($class === null) {
        continue;
    }

    $reflection = new ReflectionClass($class);
    $traitMethods = [];

    foreach ($reflection->getTraits() as $trait) {
        foreach ($trait->getMethods() as $method) {
            $traitMethods[$method->getName()] = true;
        }
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $ownTrait = $reflection->isTrait();

        if ($method->getDeclaringClass()->getName() !== $class && ! $ownTrait) {
            continue;
        }

        if (! $ownTrait && isset($traitMethods[$method->getName()]) && $class !== U\Components\Concerns\BuildsContent::class) {
            continue;
        }

        if (preg_match($skipped, $method->getName()) === 1 && ! ($class === U\Document::class && in_array($method->getName(), ['settings', 'schema', 'styles'], true))) {
            continue;
        }

        $line = '- `'.$signature($method).'`';
        $text = $summary($method);
        $out[] = $text === '' ? $line : $line.': '.$text;
    }

    $out[] = '';
}

$out[] = '## Enums';
$out[] = '';

$out[] = 'Methods typed with these enums also take the string value.';
$out[] = '';

foreach ($enums as $enum) {
    $cases = array_map(static fn (UnitEnum $case): string => $case instanceof BackedEnum ? $case->name." ('".$case->value."')" : $case->name, $enum::cases());
    $out[] = '- `'.(new ReflectionClass($enum))->getShortName().'`: '.implode(', ', $cases);
}

$out[] = '';

$target = dirname(__DIR__).'/resources/boost/skills/laravel-uniexporter/references/api.md';
@mkdir(dirname($target), 0777, true);
file_put_contents($target, implode("\n", $out));

echo 'Wrote '.$target.' ('.count($out)." lines)\n";
