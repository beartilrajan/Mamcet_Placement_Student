<?php
// MAMCET Placement & Learning Portal - Job Description Matching Service

require_once(__DIR__ . '/AIService.php');

class JobMatchService {
    /**
     * Compare a student's resume against a job description.
     */
    public static function match(int $studentId, int $resumeId, int $jobId, string $resumeText, array $jobData, $db = null): array {
        if ($db === null) {
            require_once(__DIR__ . '/../config/database.php');
            $db = Database::getInstance()->getConnection();
        }

        // 1. Compress and clean resume text and job description
        $compressedResume = AIService::compressText($resumeText, 2000);

        // 2. Build Job Description context text
        $jobTitle = $jobData['job_title'] ?? 'Role';
        $jobRole = $jobData['job_role'] ?? '';
        $companyName = $jobData['company_name'] ?? 'Company';
        $skillsRequired = $jobData['required_skills'] ?? '';
        $summary = $jobData['job_summary'] ?? '';
        $responsibilities = $jobData['responsibilities'] ?? '';

        $jobDetailsRaw = "Company: $companyName\nRole: $jobTitle ($jobRole)\nRequired Skills: $skillsRequired\nSummary: $summary\nResponsibilities: $responsibilities";
        $compressedJd = AIService::compressText($jobDetailsRaw, 1000);

        // 3. High-density, token-efficient System Prompt & User prompt
        $systemPrompt = "You are a campus placement ATS Job Match Engine. Compare the candidate resume against the job description.\n"
                      . "Analyze objectively. Keep bullet points crisp (max 2-3 per list). Respond ONLY with valid raw JSON:\n"
                      . "{\n"
                      . "  \"match_score\": (integer 0-100),\n"
                      . "  \"role_suitability\": \"Concise 2-sentence summary of candidate fit.\",\n"
                      . "  \"education_match\": \"Brief qualification match (e.g., Matched B.E./B.Tech criteria)\",\n"
                      . "  \"project_relevance\": \"Brief evaluation of resume projects matching this role\",\n"
                      . "  \"internship_relevance\": \"Brief evaluation of internship or practical training fit\",\n"
                      . "  \"matching_skills\": [\"Skill 1\", \"Skill 2\", \"Skill 3\"],\n"
                      . "  \"missing_skills\": [\"Skill 1\", \"Skill 2\"],\n"
                      . "  \"matching_keywords\": [\"Keyword 1\", \"Keyword 2\"],\n"
                      . "  \"missing_keywords\": [\"Keyword 1\", \"Keyword 2\"],\n"
                      . "  \"strong_sections\": [\"Projects\", \"Skills\"],\n"
                      . "  \"weak_sections\": [\"Certifications\"],\n"
                      . "  \"suggested_modifications\": [\"Tweak 1\", \"Tweak 2\"],\n"
                      . "  \"recommended_learning\": [\"Topic 1\", \"Topic 2\"]\n"
                      . "}";

        $prompt = "--- JOB DESCRIPTION ---\n"
                . $compressedJd
                . "\n\n"
                . "--- CANDIDATE RESUME ---\n"
                . $compressedResume
                . "\n------------------------";

        try {
            // Call AI structured JSON service with token limit
            $results = AIService::generateStructuredJson($db, $studentId, 'job_matching', $prompt, $systemPrompt, ['max_tokens' => 800]);
        } catch (Exception $e) {
            // Fallback object on exception
            $results = [
                'match_score' => 50,
                'role_suitability' => 'AI Matching Service is temporarily busy. Applied rule-based keywords evaluation.',
                'education_match' => 'Partially Match',
                'project_relevance' => 'Review projects inside the resume manually.',
                'internship_relevance' => 'Review internship history manually.',
                'matching_skills' => [],
                'missing_skills' => [],
                'matching_keywords' => [],
                'missing_keywords' => [],
                'strong_sections' => ['Review Resume manually'],
                'weak_sections' => [],
                'suggested_modifications' => ['Please retry re-matching later for AI phrase suggestions'],
                'recommended_learning' => ['Brush up core topics for the ' . $jobTitle . ' role.']
            ];
        }

        // 4. Save match history log to MySQL database table `resume_job_matches`
        try {
            if (class_exists('Database')) {
                $db = Database::getInstance()->getConnection();
            }
            $stmt = $db->prepare("
                INSERT INTO resume_job_matches 
                (student_id, resume_id, job_id, match_score, matching_skills, missing_skills, matching_keywords, missing_keywords, recommendations) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $studentId,
                $resumeId,
                $jobId,
                (int)$results['match_score'],
                json_encode($results['matching_skills']),
                json_encode($results['missing_skills']),
                json_encode($results['matching_keywords']),
                json_encode($results['missing_keywords']),
                json_encode([
                    'role_suitability' => $results['role_suitability'],
                    'education_match' => $results['education_match'],
                    'project_relevance' => $results['project_relevance'],
                    'internship_relevance' => $results['internship_relevance'],
                    'strong_sections' => $results['strong_sections'],
                    'weak_sections' => $results['weak_sections'],
                    'suggested_modifications' => $results['suggested_modifications'],
                    'recommended_learning' => $results['recommended_learning']
                ])
            ]);
        } catch (Exception $ex) {
            // Log insert issue silently
            error_log("Failed to insert match log: " . $ex->getMessage());
        }

        return $results;
    }
}
