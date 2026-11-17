<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use BadMethodCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\Columns;
use Wovosoft\LaravelUniexporter\Components\Group;
use Wovosoft\LaravelUniexporter\Components\HeaderCell;
use Wovosoft\LaravelUniexporter\Components\Heading;
use Wovosoft\LaravelUniexporter\Components\KeyValue;
use Wovosoft\LaravelUniexporter\Components\Native;
use Wovosoft\LaravelUniexporter\Components\Run;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Components\Text;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Drivers\RenderContext;
use Wovosoft\LaravelUniexporter\Style\Paper;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

/**
 * The chainable API builds the same components as a schema, so every driver renders it.
 */
class FluentApiTest extends TestCase
{
    private function document(): Document
    {
        return Document::make()
            ->paper(Paper::A4)
            ->landscape()
            ->margins(10)
            ->font('FreeSerif', 10)
            ->title('Salaries of {office}')
            ->filename('salaries')
            ->footer(right: '{page}/{pages}')
            ->headerContent(fn (Group $header) => $header->text('Head Office', fn (Text $text) => $text->center()))
            ->with('office', 'Dhaka Branch')
            ->withData(['employees' => [
                ['job_id' => '007', 'name' => 'Rahim', 'salary' => 1000, 'degrees' => [['exam' => 'SSC'], ['exam' => 'HSC']]],
                ['job_id' => '008', 'name' => 'Karim', 'salary' => 500.5, 'degrees' => []],
            ]])
            ->heading('Salaries of {office}', 2)
            ->text(fn (Text $text) => $text->run('Prepared by ')->run('HR', fn (Run $run) => $run->bold()))
            ->keyValue(['Month' => '{month}'], fn (KeyValue $keyValue) => $keyValue->columns(2))
            ->table('employees', fn (Table $table) => $table
                ->subRows('degrees')
                ->headerRow([HeaderCell::make('Employee')->colspan(4), HeaderCell::make('Pay')->rowspan(2)])
                ->column(label: 'SL', tap: fn (Column $column) => $column->rowNumber())
                ->column('job_id', 'PF No.', fn (Column $column) => $column->text())
                ->column('name')
                ->column('exam', 'Exam', fn (Column $column) => $column->fromSubRow())
                ->column('salary', 'Salary', fn (Column $column) => $column->money()->total())
                ->totalLabel('Total'))
            ->list(['First', 'Second'])
            ->link('Portal', 'https://example.com')
            ->when(true, fn (Document $document) => $document->spacer(4))
            ->unless(true, fn (Document $document) => $document->text('never shown'))
            ->each(['A', 'B'], fn (Document $document, string $letter, int $index) => $document->text("Note {$letter}{$index}"))
            ->columns(fn (Columns $columns) => $columns
                ->block(fn (Group $block) => $block->text('Prepared by')->divider())
                ->block(fn (Group $block) => $block->text('Approved by', fn (Text $text) => $text->right()), 2))
            ->group(fn (Group $group) => $group->heading('Signatures', 3)->text('Signed')->keepTogether())
            ->nativeContent(fn (Native $native) => $native->for('html', fn (RenderContext $context): string => '<p id="native">native</p>'))
            ->pageBreak()
            ->sheet('Second')
            ->text('On the second sheet');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formats(): array
    {
        return ['pdf' => ['pdf'], 'docx' => ['docx'], 'xlsx' => ['xlsx'], 'html' => ['html'], 'csv' => ['csv']];
    }

    #[DataProvider('formats')]
    public function test_a_chained_document_exports_in_strict_mode(string $format): void
    {
        $export = $this->export($this->document(), ['month' => 'November'], $format, $this->manager(['strict' => true]));

        $this->assertFileExists($export->path());
        $this->assertSame('salaries.'.$format, $export->filename());
    }

    public function test_the_chain_builds_the_same_components_and_settings(): void
    {
        $document = $this->document();
        $schema = $document->schema();
        $settings = $document->resolveSettings();

        $this->assertInstanceOf(Heading::class, $schema[0]);
        $this->assertSame(Paper::A4, $settings->getPaper());
        $this->assertTrue($settings->isLandscape());
        $this->assertSame('salaries', $settings->getFilename());
        $this->assertCount(1, $settings->getHeaderContent());

        $table = $schema[3];
        $this->assertInstanceOf(Table::class, $table);
        $this->assertSame(['SL', 'PF No.', 'Name', 'Exam', 'Salary'], array_map(fn (Column $column): string => $column->getLabel(), $table->getColumns()));
        $this->assertCount(1, $table->getHeaderRows());

        $columns = array_values(array_filter($schema, fn ($component): bool => $component instanceof Columns))[0];
        $this->assertCount(2, $columns->getBlocks());
        $this->assertEqualsWithDelta([100 / 3, 200 / 3], $columns->getWidthPercentages(), 0.001);
    }

    public function test_attached_data_fills_placeholders_and_rows_without_data(): void
    {
        $html = $this->export($this->document(), ['month' => 'November'], 'html')->contents();

        $this->assertStringContainsString('Salaries of Dhaka Branch', $html);
        $this->assertStringContainsString('November', $html);
        $this->assertStringContainsString('Rahim', $html);
        $this->assertStringContainsString('1,500.50', $html, 'The salary column totals.');
        $this->assertStringContainsString('<span style="font-weight: bold;">HR</span>', $html, 'Runs keep their own style.');
        $this->assertStringContainsString('Note A0', $html);
        $this->assertStringContainsString('Note B1', $html);
        $this->assertStringNotContainsString('never shown', $html);
        $this->assertStringContainsString('<p id="native">native</p>', $html);
    }

    public function test_data_given_to_data_overrides_attached_data(): void
    {
        $html = $this->export($this->document(), ['office' => 'Khulna Branch'], 'html')->contents();

        $this->assertStringContainsString('Salaries of Khulna Branch', $html);
        $this->assertStringNotContainsString('Dhaka Branch', $html);
    }

    public function test_unknown_methods_still_fail(): void
    {
        $this->expectException(BadMethodCallException::class);

        Document::make()->getPaper();
    }
}
