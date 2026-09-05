# Developer-maintained aptitude content

The aptitude question bank is database-backed. Student requests to `api/aptitude.php` submit answers and retrieve progress; they do not create or fetch question content from an external API.

To add or update questions without an admin UI action:

```text
php scripts/import-aptitude-content.php --file=data/aptitude/questions.json --actor=developer-name
```

Supported input formats are JSON, CSV, and TSV. JSON should contain a `questions` array. Each question needs `category`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, and `explanation`. `difficulty`, `topic`, `company_tag`, and `is_active` are optional. Use `question_id` to update a known row; otherwise an exact category/question-text match is updated and new content is inserted.

Imports are validated before writing, run in a database transaction, and recorded in `aptitude_content_imports` with the source filename, hash, actor, and counts. The importer is CLI-only and is not an admin bypass or hidden web endpoint.

Use [questions.example.json](questions.example.json) as the starting template.
