<?php
// MAMCET Placement & Learning Portal - ATS Hybrid Scoring Service

require_once(__DIR__ . '/AIService.php');

class ATSScoringService {
    /**
     * Run the hybrid analysis (Rule-based + AI feedback, optionally contextual to a JD).
     */
    public static function analyze(string $extractedText, ?string $jdText = null, ?int $studentId = null, $db = null): array {
        if ($db === null) {
            require_once(__DIR__ . '/../config/database.php');
            $db = Database::getInstance()->getConnection();
        }

        // 1. Calculate Rule-Based Baseline Score (Max: 60)
        $ruleResults = self::calculateRuleScore($extractedText);
        $ruleScore = $ruleResults['score'];

        // 2. Fetch AI-based Assessment (Generic vs Contextual)
        if (!empty(trim($jdText ?? ''))) {
            $aiResults = self::getContextualJdAssessment($extractedText, $jdText, $studentId, $db);
        } else {
            $aiResults = self::getAiAssessment($extractedText, $studentId, $db);
        }

        $aiScore = (int)($aiResults['ai_score'] ?? 20); // Default to midpoint fallback on error

        // 3. Final Combined Score (Max: 100)
        $finalScore = min(100, $ruleScore + $aiScore);

        return [
            'overall_score' => $finalScore,
            'rule_score' => $ruleScore,
            'ai_score' => $aiScore,
            'assessment_text' => $aiResults['summary'] ?? ($aiResults['role_suitability'] ?? 'Successfully completed automated parser evaluation.'),
            'strengths' => json_encode($aiResults['strengths'] ?? ($aiResults['matching_skills'] ?? [])),
            'critical_issues' => json_encode(array_merge($ruleResults['issues'], $aiResults['critical_issues'] ?? [])),
            'missing_sections' => json_encode(array_merge($ruleResults['missing_sections'], $aiResults['missing_sections'] ?? [])),
            'missing_keywords' => json_encode($aiResults['missing_keywords'] ?? ($aiResults['missing_skills'] ?? [])),
            'grammar_suggestions' => json_encode($aiResults['grammar_suggestions'] ?? []),
            'action_verb_suggestions' => json_encode($aiResults['action_verb_suggestions'] ?? []),
            'priority_actions' => json_encode($aiResults['priority_actions'] ?? ($aiResults['suggested_modifications'] ?? [])),
            'section_scores' => [
                'Contact Details' => ['score' => $ruleResults['breakdown']['contact'], 'max' => 5],
                'Professional Summary' => ['score' => $ruleResults['breakdown']['summary'], 'max' => 5],
                'Education Records' => ['score' => $ruleResults['breakdown']['education'], 'max' => 10],
                'Technical Skills' => ['score' => $ruleResults['breakdown']['skills'], 'max' => 10],
                'Academic Projects' => ['score' => $ruleResults['breakdown']['projects'], 'max' => 10],
                'Internships & Exp' => ['score' => $ruleResults['breakdown']['experience'], 'max' => 10],
                'Certifications' => ['score' => $ruleResults['breakdown']['certifications'], 'max' => 10],
            ]
        ];
    }

    /**
     * Compute section presence, formatting indicators, and email/phone checks.
     */
    private static function calculateRuleScore(string $text): array {
        $score = 0;
        $breakdown = [
            'contact' => 0,
            'summary' => 0,
            'education' => 0,
            'skills' => 0,
            'projects' => 0,
            'experience' => 0,
            'certifications' => 0
        ];
        $missing_sections = [];
        $issues = [];

        $lowercaseText = strtolower($text);

        // 1. Contact Info check (Max: 5)
        $hasEmail = preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text);
        $hasPhone = preg_match('/(?:\+?\d{1,3}[-.\s]?)?\(?\d{2,5}\)?[-.\s]?\d{3,5}[-.\s]?\d{3,5}/', $text) || preg_match('/\b\d{10}\b/', $text);
        if ($hasEmail && $hasPhone) {
            $breakdown['contact'] = 5;
        } elseif ($hasEmail || $hasPhone) {
            $breakdown['contact'] = 3;
            $issues[] = "Resume contains either email or phone, but not both contact channels.";
        } else {
            $issues[] = "Missing email or mobile contact numbers on header.";
        }

        // 2. Summary Section check (Max: 5)
        if (preg_match('/(summary|objective|career goals|profile|about me|about|professional summary|executive summary|career objective|background)/i', $text)) {
            $breakdown['summary'] = 5;
        } else {
            $missing_sections[] = "Professional Summary / Objective";
            $issues[] = "We could not locate a designated Professional Summary or Career Objective statement.";
        }

        // 3. Education Section check (Max: 10)
        if (preg_match('/(education|degree|college|university|academic|academics|qualification|qualifications|hsc|sslc|diploma|b\.?e|b\.?tech|m\.?tech|bca|mca|b\.?sc|m\.?sc|bachelor|master|school|cgpa|gpa|percentage|institution)/i', $text)) {
            $breakdown['education'] = 10;
        } else {
            $missing_sections[] = "Education";
            $issues[] = "No Education history headings found (e.g. college name, degree, GPA).";
        }

        // 4. Technical Skills Section check (Max: 10)
        if (preg_match('/(skills|technical skills|key skills|competencies|expertise|languages|technologies|tools|frameworks|programming|proficiencies|core competencies|stack|database|java|python|c\+\+|javascript|react|node|html|css|sql)/i', $text)) {
            $breakdown['skills'] = 10;
        } else {
            $missing_sections[] = "Technical Skills";
            $issues[] = "Technical skills section is missing. Headings like 'Skills' or 'Core Competencies' are advised.";
        }

        // 5. Projects Section check (Max: 10)
        if (preg_match('/(projects|academic projects|key projects|mini projects|major projects|personal projects|capstone|portfolio|project title|project description)/i', $text)) {
            $breakdown['projects'] = 10;
        } else {
            $missing_sections[] = "Projects";
            $issues[] = "No academic or personal project records detected on your profile.";
        }

        // 6. Experience / Internship Section check (Max: 10)
        if (preg_match('/(experience|internship|internships|intern|work history|employment|practical training|industrial training|work experience|training|freelance)/i', $text)) {
            $breakdown['experience'] = 10;
        } else {
            $missing_sections[] = "Internships / Work Experience";
            // Not a critical issue for freshers, so we list as informational warning
        }

        // 7. Certifications / Achievements check (Max: 10)
        if (preg_match('/(certification|certifications|certified|certificate|certificates|achievements|awards|workshops|workshop|co-curricular|extra-curricular|extracurricular|publications|courses|participations|seminar|webinar)/i', $text)) {
            $breakdown['certifications'] = 10;
        } else {
            $missing_sections[] = "Certifications & Achievements";
        }

        // Calculate Rule Sum
        $score = array_sum($breakdown);

        return [
            'score' => $score,
            'breakdown' => $breakdown,
            'missing_sections' => $missing_sections,
            'issues' => $issues
        ];
    }

    /**
     * Request qualitative scoring and text parsing from Gemini or OpenAI provider.
     */
    private static function getAiAssessment(string $text, ?int $studentId, $db): array {
        // Compress text to preserve technical substance and project specifics
        $compressedText = AIService::compressText($text, 2400);
        $scanSession = date('Y-m-d H:i:s');

        // High-caliber, recruiter-grade system prompt for campus placement ATS audit
        $systemPrompt = "You are a Senior Technical Recruiter and Chief ATS Algorithm Auditor for tier-1 campus placements.\n"
                      . "Perform a rigorous, objective evaluation of the candidate's resume across 5 key dimensions:\n"
                      . "1. Technical Depth: Evaluate mastery of core languages, modern frameworks, and architectural patterns vs basic buzzword listings.\n"
                      . "2. Quantifiable Impact: Scrutinize whether project accomplishments feature measurable metrics (e.g. latency reduction, scale, users, %, uptime).\n"
                      . "3. Action Verbs & Voice: Detect passive job descriptions and replace them with high-impact power verbs.\n"
                      . "4. ATS Keyword Density: Identify high-demand industry tools and concepts missing from their tech stack.\n"
                      . "5. Bullet Phrasing: Provide concrete before-and-after bullet rewrites using Google's XYZ formula: 'Accomplished [X] as measured by [Y], by doing [Z]'.\n\n"
                      . "Provide fresh, specific, non-generic feedback. Return ONLY valid raw JSON:\n"
                      . "{\n"
                      . "  \"ai_score\": (integer 0-40, objectively scored based on technical rigor, project depth, and measurable outcomes),\n"
                      . "  \"summary\": \"2-3 punchy, authoritative sentences detailing candidate hiring competitiveness and highest-leverage improvements.\",\n"
                      . "  \"strengths\": [\"Specific standout technical strength with concrete evidence from resume\", \"Strong project architecture or milestone highlight\"],\n"
                      . "  \"critical_issues\": [\"High-priority resume defect (e.g., lack of quantified metrics, vague bullet points, unverified tech stack claims)\"],\n"
                      . "  \"missing_sections\": [],\n"
                      . "  \"missing_keywords\": [\"Crucial industry tools or keywords missing from candidate stack (e.g., Git/GitHub, Docker, Unit Testing, CI/CD, REST APIs, Microservices)\"],\n"
                      . "  \"grammar_suggestions\": [\"Before: '[vague bullet from resume]' -> After: '[XYZ-formula quantified rewrite with active power verb]'\"],\n"
                      . "  \"action_verb_suggestions\": [\"Domain-specific power verbs (e.g., Spearheaded, Orchestrated, Refactored, Benchmark, Automated)\"],\n"
                      . "  \"priority_actions\": [\"Step 1: Immediate high-impact resume edit to increase interview callback rate\", \"Step 2: Specific metric or skill to add before campus drives\"]\n"
                      . "}";

        $prompt = "[EVALUATION SESSION: {$scanSession}]\n\n--- EXTRACTED RESUME TEXT ---\n" . $compressedText . "\n-----------------------------";

        try {
            $result = AIService::generateStructuredJson($db, $studentId, 'ats_analysis', $prompt, $systemPrompt, ['max_tokens' => 1000, 'temperature' => 0.4]);
            if (!empty($result) && is_array($result)) {
                return $result;
            }
        } catch (\Throwable $e) {
            error_log("ATSScoringService AI notice: " . $e->getMessage() . " - switching to heuristic ATS analyzer.");
        }

        return self::getFallbackAiAssessment($text);
    }

    /**
     * Heuristic qualitative ATS assessment when AI service is unavailable or rate-limited.
     */
    private static function getFallbackAiAssessment(string $text): array {
        $lower = strtolower($text);

        // 1. Power Action Verbs check
        $powerVerbs = ['developed', 'designed', 'implemented', 'engineered', 'architected', 'optimized', 'automated', 'integrated', 'spearheaded', 'managed', 'debugged', 'resolved', 'analyzed', 'configured', 'accelerated', 'reduced', 'deployed', 'built', 'created'];
        $foundVerbs = [];
        $missingVerbs = ['Spearheaded', 'Optimized', 'Architected', 'Automated', 'Deployed', 'Refactored'];
        foreach ($powerVerbs as $v) {
            if (strpos($lower, $v) !== false) {
                $foundVerbs[] = ucfirst($v);
            }
        }

        // 2. Technical Industry Keywords check
        $industryKeywords = [
            'Git / GitHub' => ['git', 'github', 'version control'],
            'REST APIs' => ['rest api', 'restful', 'api', 'endpoints'],
            'Unit Testing' => ['unit test', 'testing', 'junit', 'pytest', 'test cases'],
            'CI/CD & DevOps' => ['ci/cd', 'docker', 'pipeline', 'deployment', 'aws', 'cloud'],
            'Database Optimization' => ['indexing', 'query optimization', 'normalization', 'schema design', 'acid'],
            'Agile / Scrum' => ['agile', 'scrum', 'sprints', 'jira'],
            'Data Structures & Algorithms' => ['data structures', 'algorithms', 'dsa', 'complexity', 'oop', 'oops']
        ];

        $missingKeywords = [];
        $matchedKeywords = [];
        foreach ($industryKeywords as $label => $synonyms) {
            $found = false;
            foreach ($synonyms as $syn) {
                if (strpos($lower, $syn) !== false) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $matchedKeywords[] = $label;
            } else {
                $missingKeywords[] = $label;
            }
        }

        // 3. Metrics and Numbers check
        $hasMetrics = preg_match('/(?:\d+%\s*|\b\d+\s*users\b|\b\d+\s*ms\b|\b\d+\s*times\b|\breduced\s+by\s+\d+|\bincreased\s+by\s+\d+)/i', $text);

        // 4. Strengths
        $strengths = [
            'Clear and organized section hierarchy suitable for ATS parsing',
            'Technical competencies prominently listed with recognized tools'
        ];
        if (!empty($foundVerbs)) {
            $strengths[] = 'Utilizes strong action verbs (' . implode(', ', array_slice($foundVerbs, 0, 3)) . ') in descriptions';
        }
        if (!empty($matchedKeywords)) {
            $strengths[] = 'Includes core industry tools (' . implode(', ', array_slice($matchedKeywords, 0, 2)) . ')';
        }

        // 5. Critical Issues & Suggestions
        $criticalIssues = [];
        if (!$hasMetrics) {
            $criticalIssues[] = 'Project bullet points lack quantifiable impact metrics (e.g. % speedup, data scale, or user counts).';
        }
        if (count($missingKeywords) >= 3) {
            $criticalIssues[] = 'Key industry standard competencies (' . implode(', ', array_slice($missingKeywords, 0, 2)) . ') are not explicitly mentioned.';
        }

        // 6. Priority Actions
        $priorityActions = [
            'Quantify your project outcomes using measurable numbers (e.g., "Reduced response latency by 25%" or "Served 500+ student profiles").',
            'Ensure every experience and project bullet point opens with a strong past-tense action verb (e.g., Developed, Engineered, Optimized).',
            'Incorporate high-frequency recruiter keywords relevant to target roles (e.g. Git, REST APIs, Unit Testing).'
        ];

        // 7. Calculate qualitative score (out of 40)
        $aiScore = 26;
        if (!empty($foundVerbs) && count($foundVerbs) >= 3) $aiScore += 4;
        if ($hasMetrics) $aiScore += 4;
        if (count($matchedKeywords) >= 2) $aiScore += 4;
        $aiScore = min(38, max(24, $aiScore));

        return [
            'ai_score' => $aiScore,
            'summary' => 'Your resume demonstrates solid foundational technical competence and clean structural organization. Enhancing bullet points with measurable impact metrics and standard industry tools (e.g., Git, REST APIs) will significantly boost recruiter and ATS ranking.',
            'strengths' => $strengths,
            'critical_issues' => $criticalIssues,
            'missing_sections' => [],
            'missing_keywords' => array_slice($missingKeywords, 0, 4),
            'grammar_suggestions' => [
                'Ensure bullet points maintain consistent punctuation and active-voice sentence structures throughout.',
                'Keep bullet descriptions concise (1-2 lines per point) for optimal recruiter readability.'
            ],
            'action_verb_suggestions' => array_slice($missingVerbs, 0, 4),
            'priority_actions' => $priorityActions
        ];
    }

    /**
     * Contextual assessment of candidate resume against a specific Job Description.
     */
    private static function getContextualJdAssessment(string $text, string $jdText, ?int $studentId, $db): array {
        $compressedResume = AIService::compressText($text, 2200);
        $compressedJd = AIService::compressText($jdText, 1200);
        $scanSession = date('Y-m-d H:i:s');

        $systemPrompt = "You are a Senior Technical Recruiter and ATS Match Algorithm conducting a targeted role gap analysis.\n"
                      . "Compare the candidate's resume directly against the job posting across 4 pillars:\n"
                      . "1. Hard Skill Alignment: Verify required languages, databases, cloud, and frameworks.\n"
                      . "2. Project & Responsibility Match: Check if the candidate's projects solve similar problems to the job requirements.\n"
                      . "3. Missing Prerequisites: Highlight deal-breaker skills or certifications stated in the JD that are absent in the resume.\n"
                      . "4. Resume Tailoring: Provide high-impact phrasing adjustments to align resume bullets directly with the hiring company's vocabulary.\n\n"
                      . "Return ONLY valid raw JSON:\n"
                      . "{\n"
                      . "  \"ai_score\": (integer 0-40, reflecting percentage alignment with JD requirements),\n"
                      . "  \"summary\": \"2-3 punchy sentences evaluating exact hiring fit and the single biggest qualification gap for this opening.\",\n"
                      . "  \"strengths\": [\"Direct match requirement 1 with project evidence from resume\", \"Direct skill match 2\"],\n"
                      . "  \"critical_issues\": [\"Primary missing prerequisite, experience deficit, or required framework mismatch\"],\n"
                      . "  \"missing_sections\": [],\n"
                      . "  \"missing_keywords\": [\"Critical required technical skills from the JD missing in candidate's resume\"],\n"
                      . "  \"grammar_suggestions\": [\"Tailoring tip: Rewrite bullet points to mirror this JD's terminology\"],\n"
                      . "  \"action_verb_suggestions\": [\"High-impact action verbs extracted from this job's responsibilities\"],\n"
                      . "  \"priority_actions\": [\"Top priority action to tailor candidate's resume before submitting to this company\"]\n"
                      . "}";

        $prompt = "[JOB MATCH SESSION: {$scanSession}]\n\n"
                . "--- TARGET JOB DESCRIPTION ---\n"
                . $compressedJd
                . "\n\n--- CANDIDATE RESUME ---\n"
                . $compressedResume
                . "\n-----------------------------";

        try {
            $result = AIService::generateStructuredJson($db, $studentId, 'ats_contextual_analysis', $prompt, $systemPrompt, ['max_tokens' => 1000, 'temperature' => 0.4]);
            if (!empty($result) && is_array($result)) {
                return $result;
            }
        } catch (\Throwable $e) {
            error_log("ATSScoringService Contextual AI notice: " . $e->getMessage() . " - switching to contextual keyword matching.");
        }

        // Heuristic fallback matching JD keywords against resume
        $lowerResume = strtolower($text);
        $lowerJd = strtolower($jdText);
        $commonTech = ['java', 'python', 'c++', 'javascript', 'typescript', 'react', 'angular', 'node', 'sql', 'mysql', 'postgresql', 'mongodb', 'docker', 'aws', 'git', 'rest api', 'spring boot', 'django', 'html', 'css', 'linux', 'data structures'];

        $matched = [];
        $missing = [];
        foreach ($commonTech as $tech) {
            if (strpos($lowerJd, $tech) !== false) {
                if (strpos($lowerResume, $tech) !== false) {
                    $matched[] = ucfirst($tech);
                } else {
                    $missing[] = ucfirst($tech);
                }
            }
        }

        $matchPercent = count($matched) + count($missing) > 0 ? intval((count($matched) / (count($matched) + count($missing))) * 40) : 28;

        return [
            'ai_score' => max(20, min(36, $matchPercent)),
            'summary' => 'Resume aligns moderately with the specified job description. Direct match identified for core competencies (' . (!empty($matched) ? implode(', ', array_slice($matched, 0, 3)) : 'general software principles') . '). Tailor project descriptions to highlight ' . (!empty($missing) ? implode(', ', array_slice($missing, 0, 2)) : 'role-specific tools') . '.',
            'strengths' => !empty($matched) ? array_map(fn($m) => "Matching Skill: $m", array_slice($matched, 0, 4)) : ['Baseline engineering qualifications aligned with job criteria'],
            'critical_issues' => !empty($missing) ? ['Missing target job requirements: ' . implode(', ', array_slice($missing, 0, 3))] : ['Add specific project examples tailored to target role requirements.'],
            'missing_sections' => [],
            'missing_keywords' => array_slice($missing, 0, 5),
            'grammar_suggestions' => ['Mirror technical keywords exactly as phrased in the target Job Description to maximize automated parsing match rates.'],
            'action_verb_suggestions' => ['Architected', 'Implemented', 'Engineered', 'Optimized'],
            'priority_actions' => [
                'Add projects or certifications explicitly demonstrating ' . (!empty($missing) ? implode(', ', array_slice($missing, 0, 2)) : 'target tech stack proficiencies') . '.',
                'Align summary statement to highlight interest in this specific engineering domain.'
            ]
        ];
    }
}
