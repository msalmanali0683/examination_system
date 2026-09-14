<?php

namespace App\Livewire\Teachers;

use App\Livewire\Concerns\HandlesExcelUpload;
use App\Models\Teacher;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Import extends Component
{
    use HandlesExcelUpload;

    private const TARGET_FIELDS = [
        'name' => 'Name',
        'designation' => 'Designation',
        'department' => 'Department',
        'email' => 'Email',
        'phone' => 'Phone',
    ];

    /** @var array<string, string|int|null> target field => source column index */
    public array $mapping = [];

    public string $step = 'upload';

    public array $report = [];

    public int $createdCount = 0;

    public int $updatedCount = 0;

    public function mount(): void
    {
        $this->authorize('manage_teachers');
    }

    public function targetFields(): array
    {
        return self::TARGET_FIELDS;
    }

    public function updatedFile(): void
    {
        $this->authorize('manage_teachers');

        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);

        $this->storeUploadedFile();
        $this->detectBestSheet();
        $this->loadSourceColumns();

        $this->mapping = $this->guessMapping();
        $this->step = 'map';
    }

    public function selectSheet(int $index): void
    {
        $this->authorize('manage_teachers');
        $this->selectedSheetIndex = $index;
        $this->loadSourceColumns();
        $this->mapping = $this->guessMapping();
    }

    public function confirmMapping(): void
    {
        $this->authorize('manage_teachers');

        if ($this->mappedIndex('name') === null) {
            $this->addError('mapping.name', 'Please map the Name column before continuing.');

            return;
        }

        $this->report = $this->buildReport();
        $this->step = 'review';
    }

    public function commitImport(): void
    {
        $this->authorize('manage_teachers');

        $created = 0;
        $updated = 0;

        foreach ($this->readDataRows() as $row) {
            if ($this->rowIsBlank($row)) {
                continue;
            }

            $name = $this->cell($row, 'name');

            if ($name === null) {
                continue;
            }

            $email = $this->cell($row, 'email');

            $attributes = [
                'name' => $name,
                'designation' => $this->cell($row, 'designation'),
                'department' => $this->cell($row, 'department'),
                'phone' => $this->cell($row, 'phone'),
                'email' => $email,
            ];

            if ($email !== null) {
                $teacher = Teacher::updateOrCreate(['email' => $email], $attributes);
                $teacher->wasRecentlyCreated ? $created++ : $updated++;
            } else {
                Teacher::create($attributes);
                $created++;
            }
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
        $seenEmails = [];
        $duplicateEmails = 0;
        $missingName = 0;
        $valid = 0;
        $total = 0;

        foreach ($this->readDataRows() as $index => $row) {
            if ($this->rowIsBlank($row)) {
                continue;
            }

            $total++;
            $excelRow = $index + 1;
            $name = $this->cell($row, 'name');
            $email = $this->cell($row, 'email');

            if ($name === null) {
                $missingName++;
                $errors[] = ['row' => $excelRow, 'message' => 'Missing name — this row will be skipped.'];

                continue;
            }

            if ($email !== null) {
                $emailKey = strtolower($email);

                if (isset($seenEmails[$emailKey])) {
                    $duplicateEmails++;
                    $errors[] = [
                        'row' => $excelRow,
                        'message' => "Duplicate email '{$email}' also seen on row {$seenEmails[$emailKey]} — this row will overwrite that teacher's details.",
                    ];
                }

                $seenEmails[$emailKey] = $excelRow;
            }

            $valid++;
        }

        return [
            'total' => $total,
            'valid' => $valid,
            'missingName' => $missingName,
            'duplicateEmails' => $duplicateEmails,
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

    private function mappedIndex(string $field): ?int
    {
        $value = $this->mapping[$field] ?? null;

        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function guessMapping(): array
    {
        return collect(self::TARGET_FIELDS)->keys()->mapWithKeys(function (string $field) {
            $match = collect($this->sourceColumns)->first(function (array $col) use ($field) {
                $label = strtolower($col['label']);

                return str_contains($label, $field) || ($field === 'name' && str_contains($label, 'teacher'));
            });

            return [$field => $match['index'] ?? null];
        })->all();
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.teachers.import');
    }
}
