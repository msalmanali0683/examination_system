<?php

namespace App\Livewire\Rooms;

use App\Livewire\Concerns\HandlesExcelUpload;
use App\Models\Room;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Import extends Component
{
    use HandlesExcelUpload;

    private const TARGET_FIELDS = [
        'name' => 'Room Name',
        'room_type' => 'Room Type',
        'rows' => 'Rows',
        'columns' => 'Columns',
        'capacity' => 'Capacity',
    ];

    /** @var array<string, string|int|null> target field => source column index */
    public array $mapping = [];

    public string $step = 'upload';

    public array $report = [];

    public int $createdCount = 0;

    public int $updatedCount = 0;

    public function mount(): void
    {
        $this->authorize('manage_rooms');
    }

    public function targetFields(): array
    {
        return self::TARGET_FIELDS;
    }

    public function updatedFile(): void
    {
        $this->authorize('manage_rooms');

        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);

        $this->storeUploadedFile();
        $this->detectBestSheet();
        $this->loadSourceColumns();

        $this->mapping = $this->guessMapping();
        $this->step = 'map';
    }

    public function selectSheet(int $index): void
    {
        $this->authorize('manage_rooms');
        $this->selectedSheetIndex = $index;
        $this->loadSourceColumns();
        $this->mapping = $this->guessMapping();
    }

    public function confirmMapping(): void
    {
        $this->authorize('manage_rooms');

        if ($this->mappedIndex('name') === null) {
            $this->addError('mapping.name', 'Please map the Room Name column before continuing.');

            return;
        }

        if ($this->mappedIndex('rows') === null) {
            $this->addError('mapping.rows', 'Please map the Rows column before continuing.');

            return;
        }

        if ($this->mappedIndex('columns') === null) {
            $this->addError('mapping.columns', 'Please map the Columns column before continuing.');

            return;
        }

        $this->report = $this->buildReport();
        $this->step = 'review';
    }

    public function commitImport(): void
    {
        $this->authorize('manage_rooms');

        $created = 0;
        $updated = 0;

        foreach ($this->readDataRows() as $row) {
            if ($this->rowIsBlank($row)) {
                continue;
            }

            $name = $this->cell($row, 'name');
            $rows = $this->intCell($row, 'rows');
            $columns = $this->intCell($row, 'columns');

            if ($name === null || $rows === null || $columns === null) {
                continue;
            }

            $maxCapacity = $rows * $columns;
            $capacity = min($this->intCell($row, 'capacity') ?? $maxCapacity, $maxCapacity);

            $roomType = strtolower((string) $this->cell($row, 'room_type')) === 'lab' ? 'lab' : 'regular';

            $room = Room::updateOrCreate(
                ['name' => $name],
                [
                    'rows' => $rows,
                    'columns' => $columns,
                    'capacity' => max(1, $capacity),
                    'room_type' => $roomType,
                ]
            );

            $room->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->cleanupUploadedFile();
        $this->createdCount = $created;
        $this->updatedCount = $updated;
        $this->step = 'done';
    }

    public function startOver(): void
    {
        $this->cleanupUploadedFile();
        $this->reset(['file', 'storedPath', 'sourceColumns', 'availableSheets', 'selectedSheetIndex', 'mapping', 'report', 'createdCount', 'updatedCount']);
        $this->step = 'upload';
    }

    private function buildReport(): array
    {
        $errors = [];
        $seenNames = [];
        $duplicateNames = 0;
        $missingRequired = 0;
        $capacityAdjusted = 0;
        $valid = 0;
        $total = 0;

        foreach ($this->readDataRows() as $index => $row) {
            if ($this->rowIsBlank($row)) {
                continue;
            }

            $total++;
            $excelRow = $index + 1;
            $name = $this->cell($row, 'name');
            $rows = $this->intCell($row, 'rows');
            $columns = $this->intCell($row, 'columns');

            if ($name === null || $rows === null || $columns === null) {
                $missingRequired++;
                $errors[] = ['row' => $excelRow, 'message' => 'Missing name, rows or columns — this row will be skipped.'];

                continue;
            }

            $maxCapacity = $rows * $columns;
            $capacityRaw = $this->intCell($row, 'capacity');

            if ($capacityRaw !== null && $capacityRaw > $maxCapacity) {
                $capacityAdjusted++;
                $errors[] = [
                    'row' => $excelRow,
                    'message' => "Capacity {$capacityRaw} exceeds the {$rows}\u{00d7}{$columns} grid ({$maxCapacity} seats) — it will be capped at {$maxCapacity}.",
                ];
            }

            $nameKey = strtolower($name);

            if (isset($seenNames[$nameKey])) {
                $duplicateNames++;
                $errors[] = [
                    'row' => $excelRow,
                    'message' => "Duplicate room name '{$name}' also seen on row {$seenNames[$nameKey]} — this row will overwrite that room's details.",
                ];
            }

            $seenNames[$nameKey] = $excelRow;

            $valid++;
        }

        return [
            'total' => $total,
            'valid' => $valid,
            'missingRequired' => $missingRequired,
            'duplicateNames' => $duplicateNames,
            'capacityAdjusted' => $capacityAdjusted,
            'errors' => array_slice($errors, 0, 50),
            'errorsTruncated' => count($errors) > 50,
        ];
    }

    private function cell(Collection $row, string $field): ?string
    {
        $index = $this->mappedIndex($field);

        if ($index === null) {
            return null;
        }

        $value = trim((string) $row->get($index));

        return $value !== '' ? $value : null;
    }

    private function intCell(Collection $row, string $field): ?int
    {
        $value = $this->cell($row, $field);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function mappedIndex(string $field): ?int
    {
        $value = $this->mapping[$field] ?? null;

        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function guessMapping(): array
    {
        $patterns = [
            'name' => ['name'],
            'room_type' => ['type'],
            'rows' => ['row'],
            'columns' => ['column', 'col'],
            'capacity' => ['capacity', 'seats'],
        ];

        return collect(self::TARGET_FIELDS)->keys()->mapWithKeys(function (string $field) use ($patterns) {
            $needles = $patterns[$field];
            $match = collect($this->sourceColumns)->first(function (array $col) use ($needles) {
                $label = strtolower($col['label']);

                foreach ($needles as $needle) {
                    if (str_contains($label, $needle)) {
                        return true;
                    }
                }

                return false;
            });

            return [$field => $match['index'] ?? null];
        })->all();
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.rooms.import');
    }
}
