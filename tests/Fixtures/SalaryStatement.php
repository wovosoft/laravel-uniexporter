<?php

namespace Wovosoft\LaravelUniexporter\Tests\Fixtures;

use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\Columns;
use Wovosoft\LaravelUniexporter\Components\Divider;
use Wovosoft\LaravelUniexporter\Components\Group;
use Wovosoft\LaravelUniexporter\Components\HeaderCell;
use Wovosoft\LaravelUniexporter\Components\Heading;
use Wovosoft\LaravelUniexporter\Components\Image;
use Wovosoft\LaravelUniexporter\Components\KeyValue;
use Wovosoft\LaravelUniexporter\Components\Link;
use Wovosoft\LaravelUniexporter\Components\ListBlock;
use Wovosoft\LaravelUniexporter\Components\PageBreak;
use Wovosoft\LaravelUniexporter\Components\Run;
use Wovosoft\LaravelUniexporter\Components\Sheet;
use Wovosoft\LaravelUniexporter\Components\Spacer;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Components\Text;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Settings;
use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * A document using every component, as a class-based schema.
 */
class SalaryStatement extends Document
{
    public function settings(Settings $settings): Settings
    {
        return $settings
            ->paper('A4')
            ->landscape()
            ->margins(15, 10)
            ->font('FreeSerif', 10)
            ->title('{title} - {month}')
            ->author('{prepared}')
            ->filename('salary-statement')
            ->header(left: '{office.name}', right: 'Printed: {date}')
            ->footer(center: 'Page {page} of {pages}');
    }

    public function styles(): array
    {
        return [
            'muted' => Style::make()->color('666666')->italic(),
        ];
    }

    public function schema(): array
    {
        return [
            Sheet::make('Summary'),
            Image::make('{logo}')->width(20),
            Heading::make('{title}')->center(),
            Text::make('Office: {office.name} ({office.code})')->style('muted')->center(),
            KeyValue::make(['Month' => '{month}', 'Prepared by' => '{prepared}'])->columns(2),
            Text::make()->runs([Run::make('Note: ')->bold(), Run::make('confidential '), Run::make('& internal')->italic()]),
            ListBlock::make(['Static note'])->source('notes'),
            Link::make('Policy', 'https://example.com/policy?a=1&b=2'),
            Divider::make(),
            Table::make('employees')
                ->headerRows([[HeaderCell::make('Employee')->colspan(3), HeaderCell::make('Service')->colspan(2), HeaderCell::make('')]])
                ->columns([
                    Column::make()->rowNumber()->label('SL')->width(0.5),
                    Column::make('pf_number')->label('PF No'),
                    Column::make('name')->width(2.5),
                    Column::make('designation.name')->label('Designation')->width(2),
                    Column::make('joined_at')->label('Joined')->date('d/m/Y'),
                    Column::make('basic_salary')->label('Basic')->money()->total(),
                    Column::make('remarks')->emptyValue('-'),
                ])
                ->striped()
                ->totalLabel('Grand Total'),
            Spacer::make(8),
            Columns::make([
                [Text::make('Prepared by'), Text::make('{prepared}')->bold()],
                Group::make([Text::make('Approved by'), Text::make('Head of Office')->bold()])->keepTogether(),
            ])->widths([1, 1]),
            PageBreak::make(),
            Sheet::make('Notes'),
            Heading::make('Notes', 2),
            Text::make('Only in PDF')->visibleOn('pdf'),
            Text::make('Hidden in spreadsheets')->hiddenOn('xlsx'),
        ];
    }
}
