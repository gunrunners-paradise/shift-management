<?php

namespace App\Services;

use App\Http\Requests\StoreUserRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use RuntimeException;

class WorkerCsvReader
{
    private const REQUIRED_COLUMNS = ['username', 'first_name', 'last_name', 'gender', 'password'];

    /**
     * Read and validate one private local CSV without changing the database.
     * Relative paths start at the Laravel project root. Buffer validated rows
     * once so the eventual import never rereads a file changed after validation.
     * Diagnostics contain field names and physical line numbers, never values.
     *
     * @return array{workers: list<array<string, mixed>>, errors: list<string>, invalid: int}
     */
    public function read(string $path): array
    {
        if ($path === '' || str_contains($path, "\0")) {
            return $this->failure('CSV path must identify a readable local file.');
        }

        $localPath = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        $resolvedPath = realpath($localPath);

        // Regular local files exclude remote URLs, stream wrappers and pipes.
        if ($resolvedPath === false || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            return $this->failure('CSV file does not exist or is not a readable regular local file.');
        }

        $stream = @fopen($resolvedPath, 'rb');

        if ($stream === false) {
            return $this->failure('CSV file could not be opened for reading.');
        }

        try {
            // Strip the BOM before parsing: it can precede a quoted header.
            if (fread($stream, 3) !== "\xEF\xBB\xBF") {
                rewind($stream);
            }

            return $this->readRecords($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function readRecords($stream): array
    {
        $header = null;
        $workers = [];
        $errors = [];
        $invalid = 0;
        $seenUsernames = [];
        $line = 1;
        $rules = (new StoreUserRequest)->rules();

        // Existing accounts are skipped, not invalid. Every other creation
        // rule, including the bcrypt byte limit, remains shared with the UI.
        foreach ($rules['username'] as $index => $rule) {
            if ($rule instanceof Unique) {
                unset($rules['username'][$index]);
            }
        }

        while (! feof($stream)) {
            $start = ftell($stream);
            $values = fgetcsv($stream, null, ',', '"', '');
            $end = ftell($stream);

            if ($values === false) {
                if (! feof($stream)) {
                    throw new RuntimeException('CSV read failed.');
                }

                break;
            }

            // PHP accepts malformed quoting. Check the original logical record
            // as well, while leaving actual field decoding to PHP's CSV parser.
            fseek($stream, $start);
            $record = fread($stream, $end - $start);
            $recordLine = $line;
            $line += substr_count($record, "\n");

            if ($record === "\n" || $record === "\r\n") {
                continue;
            }

            if (! mb_check_encoding($record, 'UTF-8') || str_contains($record, "\0")) {
                $message = "Row {$recordLine}: CSV must contain valid UTF-8 text without NUL bytes.";

                if ($header === null) {
                    return $this->failure($message);
                }

                $errors[] = $message;
                $invalid++;

                continue;
            }

            if (! $this->hasValidQuoting($record)) {
                $message = "Row {$recordLine}: Malformed CSV quoting; quote complete fields and double embedded quotes.";

                if ($header === null) {
                    return $this->failure($message);
                }

                $errors[] = $message;
                $invalid++;

                continue;
            }

            if ($header === null) {
                $headerError = $this->headerError($values);

                if ($headerError !== null) {
                    return $this->failure("Row {$recordLine}: {$headerError}");
                }

                $header = $values;

                continue;
            }

            if (count($values) !== count($header)) {
                $errors[] = "Row {$recordLine}: Incorrect number of CSV fields; expected ".count($header).'.';
                $invalid++;

                continue;
            }

            $attributes = array_combine($header, $values);

            foreach ($attributes as $field => $value) {
                // HTTP account forms trim text but preserve literal passwords.
                if ($field !== 'password') {
                    $attributes[$field] = Str::trim($value);
                }
            }

            $attributes['username'] = Str::lower($attributes['username']);
            $attributes['birth_date'] = ($attributes['birth_date'] ?? '') === '' ? null : $attributes['birth_date'];
            $attributes['role'] = 'worker';
            $attributes['password_confirmation'] = $attributes['password'];
            $validator = Validator::make($attributes, $rules);
            $rowHasErrors = false;

            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = "Row {$recordLine}: {$field}: {$message}";
                }

                $rowHasErrors = true;
            }

            $username = $attributes['username'];

            if (isset($seenUsernames[$username])) {
                $errors[] = "Row {$recordLine}: Duplicate username within CSV (first appears on row {$seenUsernames[$username]}).";
                $rowHasErrors = true;
            } else {
                $seenUsernames[$username] = $recordLine;
            }

            if ($rowHasErrors) {
                $invalid++;
            } else {
                $validated = $validator->validated();
                unset($validated['password_confirmation']);
                $workers[] = $validated;
            }
        }

        if ($header === null && $errors === []) {
            return $this->failure('Row 1: CSV must contain a header row.');
        }

        return ['workers' => $workers, 'errors' => $errors, 'invalid' => $invalid];
    }

    /** Check exact known header names without echoing untrusted CSV content. */
    private function headerError(array $header): ?string
    {
        if (count(array_unique($header)) !== count($header)) {
            return 'Duplicate CSV column names are not allowed.';
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, $header);

        if ($missing !== []) {
            return 'Missing required CSV columns: '.implode(', ', $missing).'.';
        }

        if (array_diff($header, [...self::REQUIRED_COLUMNS, 'birth_date']) !== []) {
            return 'Unexpected CSV columns. Allowed columns: username, first_name, last_name, gender, birth_date (optional), password.';
        }

        return null;
    }

    /**
     * Enforce ordinary comma/double-quote CSV grammar before accepting a row.
     * Newlines are allowed inside quoted fields. A closing quote must be
     * followed by another escaped quote, a comma or the end of the record.
     */
    private function hasValidQuoting(string $record): bool
    {
        if (str_ends_with($record, "\r\n")) {
            $record = substr($record, 0, -2);
        } elseif (str_ends_with($record, "\n")) {
            $record = substr($record, 0, -1);
        }

        $state = 'start';
        $length = strlen($record);

        for ($index = 0; $index < $length; $index++) {
            $character = $record[$index];

            if ($state === 'quoted') {
                if ($character === '"') {
                    $state = 'closed';
                }

                continue;
            }

            if ($state === 'closed') {
                if ($character === '"') {
                    $state = 'quoted';
                } elseif ($character === ',') {
                    $state = 'start';
                } else {
                    return false;
                }

                continue;
            }

            if ($character === ',') {
                $state = 'start';
            } elseif ($character === '"' && $state === 'start') {
                $state = 'quoted';
            } elseif ($character === '"' || $character === "\r" || $character === "\n") {
                return false;
            } else {
                $state = 'unquoted';
            }
        }

        return $state !== 'quoted';
    }

    private function failure(string $message): array
    {
        return ['workers' => [], 'errors' => [$message], 'invalid' => 1];
    }
}
