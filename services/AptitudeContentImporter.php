<?php
// MAMCET Placement & Learning Portal - Local Aptitude Content Importer

/**
 * Imports developer-maintained aptitude questions without an HTTP/API feed.
 * The importer is intentionally usable from the CLI only (see scripts/).
 */
class AptitudeContentImporter {
    private const VALID_OPTIONS = ['A', 'B', 'C', 'D'];
    private const VALID_DIFFICULTIES = ['Easy', 'Medium', 'Hard'];

    /**
     * Import a JSON, CSV, or TSV question file into the aptitude question bank.
     * Existing rows are updated by question_id when supplied, otherwise by
    * exact category/question text. New categories are created automatically.
     */
    public static function importFile(PDO $db, string $filePath, string $actor = 'developer-cli'): array {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Aptitude content imports are available from the command line only.');
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException('Aptitude content file does not exist or is not readable: ' . $filePath);
        }

        $payload = self::readFile($filePath);
        $records = $payload['questions'];
        if (empty($records)) {
            throw new InvalidArgumentException('The aptitude content file contains no questions.');
        }

        $questions = [];
        $errors = [];
        foreach ($records as $index => $record) {
            try {
                $questions[] = self::normalizeQuestion($record, $index + 1);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new InvalidArgumentException("Aptitude import validation failed:\n- " . implode("\n- ", $errors));
        }

        self::ensureAuditTable($db);
        $startedTransaction = false;
        $inserted = 0;
        $updated = 0;
        $categoryCache = [];

        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $startedTransaction = true;
            }

            $findById = $db->prepare('SELECT question_id FROM aptitude_questions WHERE question_id = ? LIMIT 1');
            $findByText = $db->prepare('SELECT question_id FROM aptitude_questions WHERE category_id = ? AND question_text = ? LIMIT 1');
            $update = $db->prepare('UPDATE aptitude_questions SET category_id = ?, question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_option = ?, explanation = ?, difficulty = ?, topic = ?, company_tag = ?, is_active = ? WHERE question_id = ?');
            $insert = $db->prepare('INSERT INTO aptitude_questions (category_id, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, topic, company_tag, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insertWithId = $db->prepare('INSERT INTO aptitude_questions (question_id, category_id, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, topic, company_tag, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

            foreach ($questions as $question) {
                $categoryKey = strtolower($question['category']);
                if (!isset($categoryCache[$categoryKey])) {
                    $categoryCache[$categoryKey] = self::resolveCategory($db, $question['category']);
                }
                $categoryId = $categoryCache[$categoryKey];

                $existingId = 0;
                if ($question['question_id'] !== null) {
                    $findById->execute([$question['question_id']]);
                    $existingId = (int)$findById->fetchColumn();
                }
                if ($existingId <= 0) {
                    $findByText->execute([$categoryId, $question['question_text']]);
                    $existingId = (int)$findByText->fetchColumn();
                }

                $values = [
                    $categoryId,
                    $question['question_text'],
                    $question['option_a'],
                    $question['option_b'],
                    $question['option_c'],
                    $question['option_d'],
                    $question['correct_option'],
                    $question['explanation'],
                    $question['difficulty'],
                    $question['topic'],
                    $question['company_tag'],
                    $question['is_active'] ? 1 : 0
                ];

                if ($existingId > 0) {
                    $update->execute(array_merge($values, [$existingId]));
                    $updated++;
                } elseif ($question['question_id'] !== null) {
                    $insertWithId->execute(array_merge([$question['question_id']], $values));
                    $inserted++;
                } else {
                    $insert->execute($values);
                    $inserted++;
                }
            }

            if ($startedTransaction) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            self::writeAudit($db, $filePath, $actor, 0, 0, 'failed', $e->getMessage());
            throw $e;
        }

        $result = [
            'inserted' => $inserted,
            'updated' => $updated,
            'total' => $inserted + $updated,
            'source_file' => basename($filePath)
        ];
        self::writeAudit($db, $filePath, $actor, $inserted, $updated, 'completed', null);
        return $result;
    }

    private static function readFile(string $filePath): array {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension === 'json') {
            $json = file_get_contents($filePath);
            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
            }
            $questions = $data['questions'] ?? $data;
            if (!is_array($questions)) {
                throw new InvalidArgumentException('JSON must contain a questions array.');
            }
            return ['questions' => $questions];
        }

        if (!in_array($extension, ['csv', 'tsv'], true)) {
            throw new InvalidArgumentException('Supported aptitude content formats are JSON, CSV, and TSV.');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('Could not open aptitude content file.');
        }

        $delimiter = $extension === 'tsv' ? "\t" : ',';
        $headerRow = fgetcsv($handle, 0, $delimiter);
        if ($headerRow === false) {
            fclose($handle);
            throw new InvalidArgumentException('CSV/TSV file is empty.');
        }

        $headers = array_map([self::class, 'normalizeHeader'], $headerRow);
        $questions = [];
        $line = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if (count($row) === 1 && trim((string)$row[0]) === '') {
                continue;
            }
            $row = array_pad($row, count($headers), null);
            $record = [];
            foreach ($headers as $headerIndex => $header) {
                if ($header !== '') {
                    $record[$header] = $row[$headerIndex] ?? null;
                }
            }
            $record['_source_line'] = $line;
            $questions[] = $record;
        }
        fclose($handle);

        return ['questions' => $questions];
    }

    private static function normalizeHeader($header): string {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header);
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header);
        $header = trim($header, '_');

        $aliases = [
            'id' => 'question_id',
            'category_name' => 'category',
            'question' => 'question_text',
            'option1' => 'option_a',
            'option2' => 'option_b',
            'option3' => 'option_c',
            'option4' => 'option_d',
            'answer' => 'correct_option',
            'answer_option' => 'correct_option',
            'solution' => 'explanation',
            'companies' => 'company_tag'
        ];
        return $aliases[$header] ?? $header;
    }

    private static function normalizeQuestion($record, int $position): array {
        if (!is_array($record)) {
            throw new InvalidArgumentException("Question {$position} must be an object/row.");
        }

        $options = is_array($record['options'] ?? null) ? $record['options'] : [];
        $category = $record['category'] ?? $record['category_name'] ?? $record['category_id'] ?? '';
        if (is_array($category)) {
            $category = $category['name'] ?? $category['category_name'] ?? $category['slug'] ?? '';
        }

        $question = [
            'question_id' => self::optionalPositiveInt($record['question_id'] ?? $record['id'] ?? null),
            'category' => trim((string)$category),
            'question_text' => trim((string)($record['question_text'] ?? $record['question'] ?? '')),
            'option_a' => trim((string)($record['option_a'] ?? $options['A'] ?? $options['a'] ?? '')),
            'option_b' => trim((string)($record['option_b'] ?? $options['B'] ?? $options['b'] ?? '')),
            'option_c' => trim((string)($record['option_c'] ?? $options['C'] ?? $options['c'] ?? '')),
            'option_d' => trim((string)($record['option_d'] ?? $options['D'] ?? $options['d'] ?? '')),
            'correct_option' => strtoupper(trim((string)($record['correct_option'] ?? $record['answer'] ?? ''))),
            'explanation' => trim((string)($record['explanation'] ?? $record['solution'] ?? '')),
            'difficulty' => self::titleDifficulty($record['difficulty'] ?? 'Medium'),
            'topic' => trim((string)($record['topic'] ?? 'General Aptitude')),
            'company_tag' => trim((string)($record['company_tag'] ?? $record['companies'] ?? 'TCS, Infosys, Wipro')),
            'is_active' => self::booleanValue($record['is_active'] ?? true)
        ];

        $source = isset($record['_source_line']) ? ' on source line ' . (int)$record['_source_line'] : '';
        $missing = [];
        foreach (['category', 'question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_option', 'explanation'] as $field) {
            if ($question[$field] === '') {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            throw new InvalidArgumentException('Question ' . $position . $source . ' is missing: ' . implode(', ', $missing) . '.');
        }
        if (!in_array($question['correct_option'], self::VALID_OPTIONS, true)) {
            throw new InvalidArgumentException('Question ' . $position . $source . ' must use correct_option A, B, C, or D.');
        }
        if (!in_array($question['difficulty'], self::VALID_DIFFICULTIES, true)) {
            throw new InvalidArgumentException('Question ' . $position . $source . ' must use difficulty Easy, Medium, or Hard.');
        }

        return $question;
    }

    private static function resolveCategory(PDO $db, string $category): int {
        if (ctype_digit($category) && (int)$category > 0) {
            $stmt = $db->prepare('SELECT category_id FROM aptitude_categories WHERE category_id = ? LIMIT 1');
            $stmt->execute([(int)$category]);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        $slug = self::slugify($category);
        $stmt = $db->prepare('SELECT category_id FROM aptitude_categories WHERE slug = ? OR category_name = ? LIMIT 1');
        $stmt->execute([$slug, $category]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        $insert = $db->prepare('INSERT INTO aptitude_categories (category_name, slug, icon, description) VALUES (?, ?, ?, ?)');
        $insert->execute([$category, $slug, 'fa-brain', 'Developer-maintained aptitude category.']);
        return (int)$db->lastInsertId();
    }

    private static function ensureAuditTable(PDO $db): void {
        // DDL can implicitly commit in MySQL. Never run it while a caller
        // owns an active transaction; require the migration/table instead.
        if ($db->inTransaction()) {
            try {
                $db->query('SELECT import_id FROM aptitude_content_imports LIMIT 1');
                return;
            } catch (Throwable $e) {
                throw new RuntimeException('Run the aptitude migration before importing inside an active transaction.');
            }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS aptitude_content_imports (
            import_id INT AUTO_INCREMENT PRIMARY KEY,
            source_file VARCHAR(255) NOT NULL,
            source_hash CHAR(64) DEFAULT NULL,
            actor VARCHAR(100) NOT NULL,
            inserted_count INT NOT NULL DEFAULT 0,
            updated_count INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL,
            error_message TEXT DEFAULT NULL,
            imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_imported_at (imported_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function writeAudit(PDO $db, string $filePath, string $actor, int $inserted, int $updated, string $status, ?string $error): void {
        try {
            $stmt = $db->prepare('INSERT INTO aptitude_content_imports (source_file, source_hash, actor, inserted_count, updated_count, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([basename($filePath), hash_file('sha256', $filePath) ?: null, substr($actor, 0, 100), $inserted, $updated, $status, $error]);
        } catch (Throwable $e) {
            error_log('Aptitude content audit logging failed: ' . $e->getMessage());
        }
    }

    private static function slugify(string $value): string {
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
        return $slug !== '' ? substr($slug, 0, 100) : 'aptitude-category-' . substr(sha1($value), 0, 12);
    }

    private static function optionalPositiveInt($value): ?int {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    private static function titleDifficulty($value): string {
        $value = ucfirst(strtolower(trim((string)$value)));
        return $value !== '' ? $value : 'Medium';
    }

    private static function booleanValue($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed === null ? ((int)$value !== 0) : $parsed;
    }
}
