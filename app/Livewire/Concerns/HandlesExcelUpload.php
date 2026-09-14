<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Shared upload -> read mechanics for the Excel/CSV import wizards
 * (Teacher import, Enrollment import). Column mapping, validation and
 * commit logic stay bespoke per importer.
 */
trait HandlesExcelUpload
{
    use WithFileUploads;

    public $file;

    public ?string $storedPath = null;

    /** @var array<int, array{index:int, label:string}> */
    public array $sourceColumns = [];

    /** @var array<int, string> sheet index => display label, only populated when the workbook has >1 sheet */
    public array $availableSheets = [];

    public int $selectedSheetIndex = 0;

    /**
     * Copies the uploaded file's bytes directly rather than relying on
     * Livewire's TemporaryUploadedFile::store(), which does not reliably
     * mirror to the destination disk under Storage::fake() in tests.
     */
    protected function storeUploadedFile(): void
    {
        $destination = 'imports/'.Str::uuid().'.'.$this->file->getClientOriginalExtension();
        Storage::disk('local')->put($destination, file_get_contents($this->file->getRealPath()));
        $this->storedPath = $destination;
        $this->file = null;
    }

    /**
     * Real exports sometimes carry a small pivot/summary sheet ahead of
     * the actual data sheet (seen in a real department export: "Sheet2"
     * — a tiny summary — listed before "Sheet1", the real data). Blindly
     * reading the first sheet silently imports the wrong tab, so every
     * sheet is inspected and the one with the most non-blank rows is
     * pre-selected; a picker only appears when there's more than one.
     */
    protected function detectBestSheet(): void
    {
        $path = Storage::disk('local')->path($this->storedPath);
        $allSheets = Excel::toCollection(null, $this->storedPath, 'local');

        $names = [];
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $names = $reader->listWorksheetNames($path);
        } catch (\Throwable) {
            // Fall back to generic labels below (e.g. plain CSV has no named sheets).
        }

        $best = 0;
        $bestCount = -1;
        $labels = [];

        foreach ($allSheets as $index => $sheetData) {
            $nonBlankRows = $sheetData->filter(fn ($row) => ! $this->rowIsBlank($row))->count();
            $labels[$index] = ($names[$index] ?? 'Sheet '.($index + 1))." ({$nonBlankRows} rows)";

            if ($nonBlankRows > $bestCount) {
                $bestCount = $nonBlankRows;
                $best = $index;
            }
        }

        $this->availableSheets = count($labels) > 1 ? $labels : [];
        $this->selectedSheetIndex = $best;
    }

    protected function loadSourceColumns(): void
    {
        $headerRow = $this->readSheet()->first() ?? collect();

        $this->sourceColumns = $headerRow
            ->map(fn ($value, $index) => [
                'index' => $index,
                'label' => trim((string) $value) !== '' ? trim((string) $value) : $this->columnLetter($index),
            ])
            ->values()
            ->all();
    }

    protected function readDataRows(): Collection
    {
        return $this->readSheet()->slice(1);
    }

    private function readSheet(): Collection
    {
        return Excel::toCollection(null, $this->storedPath, 'local')->get($this->selectedSheetIndex);
    }

    protected function rowIsBlank(Collection $row): bool
    {
        return $row->filter(fn ($value) => trim((string) $value) !== '')->isEmpty();
    }

    protected function columnLetter(int $index): string
    {
        $letter = '';
        $index++;

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    protected function cleanupUploadedFile(): void
    {
        if ($this->storedPath) {
            Storage::disk('local')->delete($this->storedPath);
        }
    }
}
