<?php
// MAMCET Placement & Learning Portal - Resume-Based Interview Preparation Service

require_once(__DIR__ . '/AIService.php');

class InterviewService {
    /**
     * Generate customized questions based on student resume text or profile.
     */
    public static function generateQuestionSet($db, int $studentId, int $resumeId, string $resumeText): int {
        $questions = [];

        // Try AI generation if resume text is provided
        if (!empty(trim($resumeText))) {
            try {
                $compressedResume = AIService::compressText($resumeText, 2000);

                $scanSession = date('Y-m-d H:i:s');
                $systemPrompt = "You are a Principal Engineer and Lead Hiring Manager conducting campus placement interviews.\n"
                              . "Analyze the student's resume and generate 6 highly tailored, realistic, non-generic interview questions.\n"
                              . "Provide a rigorous mix:\n"
                              . "- 2 Core Technical Architecture/Deep-Dive questions on their claimed programming languages and tools.\n"
                              . "- 2 Academic/Hands-on Project questions inquiring about challenges, design decisions, and quantifiable results.\n"
                              . "- 2 Behavioral/Situational questions evaluating teamwork, conflict resolution, or handling deadlines (STAR format).\n\n"
                              . "Respond ONLY with valid raw JSON:\n"
                              . "{\n"
                              . "  \"questions\": [\n"
                              . "    {\n"
                              . "      \"category\": \"Technical\" or \"Project\" or \"Behavioral\",\n"
                              . "      \"question\": \"The actual interview question text\",\n"
                              . "      \"reason\": \"Why the recruiter asks this specific question\",\n"
                              . "      \"answer_points\": [\"Core point 1 to articulate\", \"Core point 2 with metrics/evidence\"],\n"
                              . "      \"sample_structure\": \"Structured response outline (STAR method: Situation, Task, Action, Result)\",\n"
                              . "      \"follow_up_questions\": [\"High-probability recruiter follow-up question\"],\n"
                              . "      \"mistakes_to_avoid\": \"Key pitfall or red flag that candidates often commit\"\n"
                              . "    }\n"
                              . "  ]\n"
                              . "}";

                $prompt = "[SESSION: {$scanSession}]\n\n--- CANDIDATE RESUME ---\n" . $compressedResume . "\n----------------------";

                $results = AIService::generateStructuredJson($db, $studentId, 'interview_generation', $prompt, $systemPrompt, ['max_tokens' => 1200, 'temperature' => 0.45]);
                $questions = $results['questions'] ?? [];
            } catch (\Throwable $e) {
                error_log("InterviewService AI generation notice: " . $e->getMessage() . " - switching to smart fallback question generator.");
                $questions = [];
            }
        }

        // Fallback to high-quality template generator if AI was unavailable or returned empty
        if (empty($questions)) {
            $questions = self::generateFallbackQuestions($resumeText);
        }

        // Reconnect/refresh DB instance after external AI network request
        if (class_exists('Database')) {
            $db = Database::getInstance()->getConnection();
        }

        // Insert into database
        $db->beginTransaction();
        try {
            $stmtSet = $db->prepare("INSERT INTO interview_question_sets (student_id, resume_id) VALUES (?, ?)");
            $stmtSet->execute([$studentId, $resumeId > 0 ? $resumeId : null]);
            $setId = (int)$db->lastInsertId();

            $stmtQ = $db->prepare("
                INSERT INTO interview_questions 
                (set_id, category, question, reason, answer_points, sample_structure, follow_up_questions, mistakes_to_avoid) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($questions as $q) {
                $stmtQ->execute([
                    $setId,
                    $q['category'] ?? 'General',
                    $q['question'] ?? 'Tell me about yourself and your career aspirations.',
                    $q['reason'] ?? 'Evaluates candidate background and communication skills.',
                    is_array($q['answer_points']) ? json_encode($q['answer_points']) : json_encode([$q['answer_points'] ?? 'Highlight core strengths']),
                    $q['sample_structure'] ?? 'Direct Response -> Example -> Key Takeaway',
                    is_array($q['follow_up_questions'] ?? null) ? json_encode($q['follow_up_questions']) : json_encode([]),
                    $q['mistakes_to_avoid'] ?? 'Avoid giving vague or one-word answers.'
                ]);
            }

            $db->commit();
            return $setId;
        } catch (\Throwable $e) {
            if ($db && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Intelligent fallback question generator tailored to extracted skills and placement domains.
     */
    public static function generateFallbackQuestions(string $resumeText = ''): array {
        $textLower = strtolower($resumeText);

        // Detect primary programming language
        $languages = [];
        if (strpos($textLower, 'java') !== false) $languages[] = 'Java';
        if (strpos($textLower, 'python') !== false) $languages[] = 'Python';
        if (strpos($textLower, 'c++') !== false || strpos($textLower, 'cpp') !== false) $languages[] = 'C++';
        if (strpos($textLower, 'c#') !== false) $languages[] = 'C#';
        if (strpos($textLower, 'php') !== false) $languages[] = 'PHP';
        if (strpos($textLower, 'javascript') !== false || strpos($textLower, 'js') !== false) $languages[] = 'JavaScript';
        if (strpos($textLower, 'typescript') !== false) $languages[] = 'TypeScript';

        $primaryLang = !empty($languages) ? implode(' / ', array_slice($languages, 0, 2)) : 'your preferred programming language (e.g. Java / Python / C++)';

        // Detect frameworks / technologies
        $frameworks = [];
        if (strpos($textLower, 'react') !== false) $frameworks[] = 'React';
        if (strpos($textLower, 'node') !== false) $frameworks[] = 'Node.js';
        if (strpos($textLower, 'spring') !== false) $frameworks[] = 'Spring Boot';
        if (strpos($textLower, 'django') !== false || strpos($textLower, 'flask') !== false) $frameworks[] = 'Django / Flask';
        if (strpos($textLower, 'angular') !== false) $frameworks[] = 'Angular';
        if (strpos($textLower, 'express') !== false) $frameworks[] = 'Express.js';
        if (strpos($textLower, 'laravel') !== false) $frameworks[] = 'Laravel';

        $techStack = !empty($frameworks) ? implode(', ', $frameworks) : 'full-stack frameworks or tools';

        // Detect database
        $dbTech = 'Relational Databases (SQL / MySQL)';
        if (strpos($textLower, 'mongodb') !== false) $dbTech = 'MongoDB & NoSQL';
        elseif (strpos($textLower, 'postgresql') !== false || strpos($textLower, 'postgres') !== false) $dbTech = 'PostgreSQL';
        elseif (strpos($textLower, 'oracle') !== false) $dbTech = 'Oracle DB';

        return [
            [
                'category' => 'Introduction',
                'question' => "Can you introduce yourself, highlight your academic achievements, and explain what inspired your choice in engineering?",
                'reason' => "Assess verbal clarity, professional presence, career motivation, and delivery under interview conditions.",
                'answer_points' => [
                    "Concise summary of academic background and college degrees",
                    "Key technical passions and domains of special interest",
                    "Major milestone (project, hackathon, or certification)",
                    "Clear placement objective aligned with the recruiter's company"
                ],
                'sample_structure' => "Greeting & Academic Background -> Technical Interests -> Core Highlight/Project -> Placement Aspiration",
                'follow_up_questions' => [
                    "What specific technical area do you want to specialize in over the next 2 years?",
                    "How do your college extracurriculars contribute to your leadership skills?"
                ],
                'mistakes_to_avoid' => "Reciting entire resume verbatim or giving memorized, robotic responses without genuine enthusiasm."
            ],
            [
                'category' => 'Technical Skills',
                'question' => "You have worked with {$primaryLang}. Can you explain its core memory management model, object-oriented concepts, and a performance optimization you applied?",
                'reason' => "Test depth of language fundamentals, memory lifecycle (Garbage Collection / pointers), and real-world implementation capability.",
                'answer_points' => [
                    "Explanation of OOP pillars (Encapsulation, Inheritance, Polymorphism, Abstraction)",
                    "Memory allocation (Stack vs Heap, Garbage Collection or resource deallocation)",
                    "Practical coding example where optimization improved runtime or reduced memory consumption"
                ],
                'sample_structure' => "State Preferred Concepts -> Detail Memory/Execution Model -> Provide Real Code Context -> Summarize Best Practices",
                'follow_up_questions' => [
                    "What is the difference between synchronous and asynchronous execution in your stack?",
                    "How do you prevent memory leaks in long-running applications?"
                ],
                'mistakes_to_avoid' => "Giving shallow textbook definitions without illustrating real-world application or code mechanics."
            ],
            [
                'category' => 'Projects',
                'question' => "Walk me through your most challenging academic or capstone project using {$techStack}. What was the architectural design, and what specific problem did it solve?",
                'reason' => "Validate project authenticity, candidate's individual contribution, system architecture comprehension, and engineering ownership.",
                'answer_points' => [
                    "Problem statement and target end-users",
                    "System architecture, frontend/backend integration, and database schema",
                    "Your personal hands-on contribution and technical components built",
                    "Key technical obstacle encountered and how you methodically overcame it"
                ],
                'sample_structure' => "Context & Problem -> Tech Stack & Architecture -> Personal Contribution (STAR) -> Measurable Results",
                'follow_up_questions' => [
                    "If you had to scale this project for 100,000 concurrent users, what bottlenecks would you address first?",
                    "How did you handle authentication, data security, and validation in this system?"
                ],
                'mistakes_to_avoid' => "Speaking only in vague generalities or taking credit for team members' work without knowing the implementation details."
            ],
            [
                'category' => 'Technical Skills',
                'question' => "How do you design database schemas using {$dbTech}, and what indexing or query optimization techniques do you apply to avoid performance bottlenecks?",
                'reason' => "Evaluate database design skills, normalization principles (1NF to 3NF), and query execution awareness.",
                'answer_points' => [
                    "Normalization strategy and foreign key relationships",
                    "Indexing strategies (B-Tree, clustered vs non-clustered indexes)",
                    "Techniques to prevent N+1 queries and slow joins",
                    "Transaction ACID properties and data integrity"
                ],
                'sample_structure' => "Schema Design Principle -> Indexing Strategy -> Query Optimization Example -> ACID / Transaction Safety",
                'follow_up_questions' => [
                    "When would you choose a NoSQL database over a traditional SQL relational database?",
                    "How do you analyze a query execution plan (EXPLAIN)?"
                ],
                'mistakes_to_avoid' => "Suggesting indexing every table column or ignoring transactional consistency requirements."
            ],
            [
                'category' => 'Problem Solving',
                'question' => "How do you apply Data Structures and Algorithms to optimize real-world software performance? Give an example where choosing the right data structure made a measurable difference.",
                'reason' => "Gauge practical problem-solving ability, Big-O time/space complexity analysis, and algorithmic maturity.",
                'answer_points' => [
                    "Real scenario (e.g. searching, hashing, priority queues, caching)",
                    "Data structure selection (e.g. HashMap O(1) vs Array O(N))",
                    "Time and Space complexity trade-off analysis",
                    "Resulting performance gain in milliseconds or memory efficiency"
                ],
                'sample_structure' => "State Problem & Initial Bottleneck -> Data Structure Choice -> Complexity Comparison -> Final Outcome",
                'follow_up_questions' => [
                    "How does a Hash Map handle collisions under the hood?",
                    "When is recursion preferable over an iterative approach?"
                ],
                'mistakes_to_avoid' => "Inability to state Time/Space complexity in Big-O notation for standard operations."
            ],
            [
                'category' => 'Teamwork/Behavior',
                'question' => "Describe a situation during a college project or hackathon where you faced a strict deadline or team disagreement. How did you resolve it?",
                'reason' => "Assess emotional intelligence, teamwork, conflict resolution, accountability, and composure under pressure.",
                'answer_points' => [
                    "Specific context and nature of the challenge/disagreement",
                    "Constructive communication and listening to differing viewpoints",
                    "Decisive action taken to align the team and divide workload",
                    "Positive outcome achieved on time with key takeaways"
                ],
                'sample_structure' => "Situation (What happened) -> Task (Goal) -> Action (Your specific actions) -> Result (STAR Method)",
                'follow_up_questions' => [
                    "How do you handle a teammate who is not delivering their assigned module on time?",
                    "What did this experience teach you about project risk management?"
                ],
                'mistakes_to_avoid' => "Blaming teammates or portraying yourself as a solo hero who did all the work alone."
            ],
            [
                'category' => 'Certifications',
                'question' => "What is the most significant technology or tool you have learned independently outside your classroom syllabus, and how have you applied it?",
                'reason' => "Measure self-driven learning initiative, curiosity, resourcefulness, and drive for continuous improvement.",
                'answer_points' => [
                    "Name of the skill, tool, or certification pursued",
                    "Motivation for choosing this domain beyond college syllabus",
                    "Practical application in a code repository, mini-project, or lab",
                    "Key engineering insight gained from the self-learning journey"
                ],
                'sample_structure' => "Identify Skill -> Explain Motivation -> Describe Practical Build -> Share Future Application",
                'follow_up_questions' => [
                    "How do you keep up with new technology releases and developer communities?",
                    "What is next on your technical learning roadmap?"
                ],
                'mistakes_to_avoid' => "Listing certifications without being able to explain the core concepts learned from them."
            ],
            [
                'category' => 'Career Objective',
                'question' => "Where do you envision your engineering career in the next 2 to 3 years, and why are you excited to start your career with our organization?",
                'reason' => "Evaluate career clarity, long-term commitment, organizational alignment, and professional maturity.",
                'answer_points' => [
                    "Clear progression goals from Graduate Trainee / SDE to solid core contributor",
                    "Eagerness to work on impactful enterprise scale systems",
                    "Alignment with company culture, continuous learning, and teamwork values",
                    "Adaptability to learn new tools and cross-functional technologies"
                ],
                'sample_structure' => "Short-term Placement Goal (1-2 yrs) -> Long-term Technical Growth (3-5 yrs) -> Why This Company Fits",
                'follow_up_questions' => [
                    "Are you willing to learn new programming languages or frameworks as project requirements require?",
                    "What kind of work environment enables you to perform at your best?"
                ],
                'mistakes_to_avoid' => "Stating you have no preference, mentioning only salary/perks, or expressing immediate plans for higher studies."
            ]
        ];
    }

    /**
     * Evaluate a student's response in written practice mode.
     */
    public static function evaluateAnswer($db, int $studentId, int $answerId, string $questionText, string $studentAnswer): array {
        $feedback = null;

        try {
            $systemPrompt = "You are a professional HR Placement Coach. Evaluate the candidate interview answer.\n"
                          . "Assess relevance, clarity, structure, and grammar. Keep suggestions concise (1-2 tips). Return raw JSON:\n"
                          . "{\n"
                          . "  \"relevance\": (integer 0-10),\n"
                          . "  \"clarity\": (integer 0-10),\n"
                          . "  \"structure\": (integer 0-10),\n"
                          . "  \"grammar\": \"Brief grammar and phrasing observation.\",\n"
                          . "  \"suggestions\": \"1-2 actionable improvement points.\",\n"
                          . "  \"overall_comments\": \"2-sentence summary feedback.\"\n"
                          . "}";

            $prompt = "Question: " . AIService::compressText($questionText, 300) . "\n\nCandidate Answer: " . AIService::compressText($studentAnswer, 1500);

            $feedback = AIService::generateStructuredJson($db, $studentId, 'written_practice_eval', $prompt, $systemPrompt, ['max_tokens' => 450]);
        } catch (\Throwable $e) {
            error_log("InterviewService AI evaluation notice: " . $e->getMessage() . " - switching to heuristic coach evaluation.");
            $feedback = self::heuristicEvaluateAnswer($questionText, $studentAnswer);
        }

        // Reconnect/refresh DB instance after external AI network request
        if (class_exists('Database')) {
            $db = Database::getInstance()->getConnection();
        }

        // Save feedback in database
        $stmt = $db->prepare("
            INSERT INTO interview_feedback (answer_id, relevance, clarity, structure, grammar, suggestions, overall_comments) 
            VALUES (?, ?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                relevance = VALUES(relevance), 
                clarity = VALUES(clarity), 
                structure = VALUES(structure), 
                grammar = VALUES(grammar), 
                suggestions = VALUES(suggestions), 
                overall_comments = VALUES(overall_comments)
        ");
        $stmt->execute([
            $answerId,
            (int)($feedback['relevance'] ?? 7),
            (int)($feedback['clarity'] ?? 7),
            (int)($feedback['structure'] ?? 7),
            $feedback['grammar'] ?? 'Good sentence structure and spelling.',
            $feedback['suggestions'] ?? 'Include more quantitative metrics and STAR method details.',
            $feedback['overall_comments'] ?? 'Well-attempted response. Continue practicing with structured examples.'
        ]);

        return $feedback;
    }

    /**
     * Rule-based heuristic evaluation when AI service quota is reached or unavailable.
     */
    public static function heuristicEvaluateAnswer(string $questionText, string $studentAnswer): array {
        $words = preg_split('/\s+/', trim($studentAnswer));
        $wordCount = count(array_filter($words));

        // Keywords detection
        $answerLower = strtolower($studentAnswer);
        $starKeywords = ['situation', 'task', 'action', 'result', 'because', 'implemented', 'designed', 'resolved', 'learned', 'improved', 'achieved', 'optimized', 'project', 'team', 'challenge'];
        $starMatchCount = 0;
        foreach ($starKeywords as $k) {
            if (strpos($answerLower, $k) !== false) {
                $starMatchCount++;
            }
        }

        // Score calculations
        if ($wordCount < 15) {
            $relevance = 4;
            $clarity = 5;
            $structure = 4;
            $grammar = "Your response is very short ({$wordCount} words). Recruiters look for at least 3-4 structured sentences.";
            $suggestions = "Elaborate with specific technical details, tools used, and personal contributions to demonstrate deeper knowledge.";
            $overall = "Needs expansion. Provide a complete explanation following the Situation -> Action -> Result model.";
        } elseif ($wordCount < 40) {
            $relevance = min(7, 5 + $starMatchCount);
            $clarity = 7;
            $structure = 6;
            $grammar = "Good sentence flow and vocabulary. Ensure correct punctuation and professional tone throughout.";
            $suggestions = "Add 1-2 concrete project or code examples to substantiate your points with measurable outcomes.";
            $overall = "Solid fundamental answer. Adding quantifiable results and specific tools will elevate it to top-tier placement readiness.";
        } else {
            $relevance = min(9, 7 + intval($starMatchCount / 2));
            $clarity = 8;
            $structure = min(9, 7 + intval($starMatchCount / 2));
            $grammar = "Strong vocabulary and articulate expression. Technical terminology is well-placed.";
            $suggestions = "Excellent depth! Practice delivering this answer verbally within 60-90 seconds with confident pacing.";
            $overall = "Comprehensive and structured response demonstrating clear domain knowledge and problem-solving maturity.";
        }

        return [
            'relevance' => $relevance,
            'clarity' => $clarity,
            'structure' => $structure,
            'grammar' => $grammar,
            'suggestions' => $suggestions,
            'overall_comments' => $overall
        ];
    }

    /**
     * Generate dynamic mock chat follow-up or next question.
     */
    public static function getMockChatNextQuestion($db, int $studentId, int $attemptId, int $qIndex, int $totalQ, string $lastQuestion, string $lastAnswer): string {
        $resumeText = '';
        try {
            $stmtText = $db->prepare("SELECT ret.extracted_text FROM resume_extracted_text ret 
                                     JOIN resume_files rf ON ret.resume_id = rf.resume_id 
                                     WHERE rf.student_id = ? AND rf.is_current = 1 LIMIT 1");
            $stmtText->execute([$studentId]);
            $resumeText = $stmtText->fetchColumn() ?: '';
        } catch (\Throwable $e) {
            $resumeText = '';
        }

        // Try AI generation
        try {
            if ($qIndex == 1) {
                $systemPrompt = "You are an empathetic yet rigorous Senior Technical Interviewer conducting a realistic campus recruitment interview.\n"
                              . "Greet the candidate warmly and ask a strong opening question tailored to their academic background and core technical interests.\n"
                              . "Keep it conversational and professional. Respond ONLY with raw JSON: {\"question\": \"The start question text\"}";
                
                $compressedResume = AIService::compressText($resumeText, 1500);
                $prompt = "--- CANDIDATE RESUME ---\n" . $compressedResume . "\n------------------------";
                $res = AIService::generateStructuredJson($db, $studentId, 'mock_chat_start', $prompt, $systemPrompt, ['max_tokens' => 180, 'temperature' => 0.4]);
                if (!empty($res['question'])) {
                    return $res['question'];
                }
            } else {
                $systemPrompt = "You are a Senior Technical Interviewer conducting a campus placement round.\n"
                              . "Carefully evaluate the candidate's previous response.\n"
                              . "If their answer lacked technical specifics or metrics, challenge them with a sharp follow-up.\n"
                              . "Otherwise, smoothly transition to the next important interview question (Question $qIndex of $totalQ).\n"
                              . "Keep it realistic, concise, and professional. Respond ONLY with raw JSON: {\"question\": \"Your next question text\"}";

                $prompt = "Previous Question: " . AIService::compressText($lastQuestion, 250) . "\nCandidate Answer: " . AIService::compressText($lastAnswer, 600) . "\nProgress: Question $qIndex of $totalQ";
                $res = AIService::generateStructuredJson($db, $studentId, 'mock_chat_loop', $prompt, $systemPrompt, ['max_tokens' => 180, 'temperature' => 0.4]);
                if (!empty($res['question'])) {
                    return $res['question'];
                }
            }
        } catch (\Throwable $e) {
            error_log("InterviewService mock chat AI notice: " . $e->getMessage() . " - switching to fallback question progression.");
        }

        // Dynamic fallback question progression by index
        $fallbackProgression = [
            1 => "Hello! To begin our interview today, please introduce yourself, summarize your academic journey, and tell me what motivated you to pursue engineering.",
            2 => "Thank you for that introduction. Can you describe your strongest technical skill or programming language, and how you have used it in a practical setting?",
            3 => "Great. Walk me through a major academic or personal project you worked on recently. What was your specific contribution, and what technical hurdles did you solve?",
            4 => "Tell me about a situation where you had to debug a complex issue or work under a tight deadline. What was your systematic approach?",
            5 => "Describe a time when you worked in a team and had a disagreement regarding technical direction or task division. How did you handle it?",
            6 => "How do you prioritize learning new technologies or tools when starting a project with requirements you have never encountered before?",
            7 => "Where do you see yourself technically and professionally in the next 2-3 years, and what qualities make you a strong candidate for this role?",
            8 => "Do you have any questions for me about our engineering teams, technology stacks, or workplace culture?"
        ];

        $effectiveIndex = (($qIndex - 1) % count($fallbackProgression)) + 1;
        return $fallbackProgression[$effectiveIndex] ?? "Can you share another key project or practical experience from your engineering coursework?";
    }

    /**
     * Compile final evaluation report summarizing all mock chat answers.
     */
    public static function generateMockFinalReport($db, int $studentId, int $attemptId): array {
        $qaList = [];
        try {
            $stmt = $db->prepare("
                SELECT q.question, q.category, a.student_answer, a.answer_id 
                FROM interview_answers a 
                JOIN interview_questions q ON a.question_id = q.question_id 
                WHERE a.attempt_id = ? 
                ORDER BY a.answer_id ASC
            ");
            $stmt->execute([$attemptId]);
            $qaList = $stmt->fetchAll();
        } catch (\Throwable $e) {}

        if (empty($qaList)) {
            try {
                $stmtFallback = $db->prepare("SELECT * FROM interview_answers WHERE attempt_id = ? ORDER BY answer_id ASC");
                $stmtFallback->execute([$attemptId]);
                $qaList = $stmtFallback->fetchAll();
                foreach ($qaList as &$row) {
                    $row['question'] = 'Recruiter Question ' . ($row['answer_id'] ?? '');
                    $row['category'] = 'General';
                }
            } catch (\Throwable $e) {}
        }

        $qaContext = "";
        $totalWords = 0;
        foreach ($qaList as $idx => $qa) {
            $num = $idx + 1;
            $ans = $qa['student_answer'] ?? '';
            $totalWords += str_word_count($ans);
            $qaContext .= "Q$num: " . ($qa['question'] ?? '') . "\n"
                        . "A$num: " . $ans . "\n\n";
        }

        $feedback = null;
        try {
            $systemPrompt = "You are a recruitment director compiling a mock interview scorecard.\n"
                          . "Review the conversation transcript and output raw JSON (keep bullet points concise, max 2-3 items):\n"
                          . "{\n"
                          . "  \"relevance_score\": (0-100),\n"
                          . "  \"clarity_score\": (0-100),\n"
                          . "  \"structure_score\": (0-100),\n"
                          . "  \"confidence_indication\": \"Concise assessment of communication confidence\",\n"
                          . "  \"resume_understanding\": \"Brief assessment of resume knowledge\",\n"
                          . "  \"project_explanation_rating\": \"Brief rating of project explanations\",\n"
                          . "  \"skill_explanation_rating\": \"Brief rating of technical skill communication\",\n"
                          . "  \"hr_readiness\": \"Placement Ready or Needs Practice\",\n"
                          . "  \"strengths\": [\"Strength 1\", \"Strength 2\"],\n"
                          . "  \"areas_to_improve\": [\"Improvement area 1\", \"Improvement area 2\"],\n"
                          . "  \"recommended_practice\": [\"Practice tip 1\", \"Practice tip 2\"]\n"
                          . "}";

            $prompt = "--- INTERVIEW CONVERSATION TRANSCRIPT ---\n" . AIService::compressText($qaContext, 2500) . "\n----------------------------------------";
            $feedback = AIService::generateStructuredJson($db, $studentId, 'mock_chat_final_report', $prompt, $systemPrompt, ['max_tokens' => 750]);
        } catch (\Throwable $e) {
            $avgWordsPerAnswer = count($qaList) > 0 ? intval($totalWords / count($qaList)) : 30;
            $baseScore = min(88, max(60, 55 + intval($avgWordsPerAnswer * 0.8)));

            $feedback = [
                'relevance_score' => $baseScore,
                'clarity_score' => min(92, $baseScore + 4),
                'structure_score' => min(90, $baseScore + 2),
                'confidence_indication' => 'Your written answers indicate good professional vocabulary, clear phrasing, and consistent engagement across interview rounds.',
                'resume_understanding' => 'Demonstrated solid grasp of core coursework, practical projects, and academic background.',
                'project_explanation_rating' => 'Project descriptions are clear. Emphasizing measurable metrics and the STAR framework will strengthen impact.',
                'skill_explanation_rating' => 'Good technical vocabulary articulated for primary programming and engineering concepts.',
                'hr_readiness' => $baseScore >= 75 ? 'Placement Ready (Strong Contender)' : 'Good (Needs Minor Refinement)',
                'strengths' => [
                    'Engaged attentively throughout all interview rounds',
                    'Maintained a polite and professional conversational tone',
                    'Demonstrated clear understanding of fundamental engineering concepts'
                ],
                'areas_to_improve' => [
                    'Incorporate the STAR methodology (Situation, Task, Action, Result) in project answers',
                    'Highlight specific quantitative achievements (e.g. % speedup, users served)',
                    'Elaborate further on architectural trade-offs when discussing technical choices'
                ],
                'recommended_practice' => [
                    'Practice 60-second elevator pitches for your top 2 academic projects',
                    'Review database indexing and system optimization trade-offs',
                    'Practice STAR-formatted responses for behavioral and conflict-resolution questions'
                ]
            ];
        }

        // Reconnect/refresh DB instance after external AI network request
        if (class_exists('Database')) {
            $db = Database::getInstance()->getConnection();
        }

        // Save scorecard into interview_feedback table
        try {
            $lastAnswerId = null;
            if (!empty($qaList)) {
                $lastAnswerId = (int)($qaList[count($qaList) - 1]['answer_id'] ?? 0);
            }
            if (!$lastAnswerId) {
                $stmtLast = $db->prepare("SELECT answer_id FROM interview_answers WHERE attempt_id = ? ORDER BY answer_id DESC LIMIT 1");
                $stmtLast->execute([$attemptId]);
                $lastAnswerId = (int)$stmtLast->fetchColumn();
            }

            if ($lastAnswerId > 0) {
                $stmtUpd = $db->prepare("
                    INSERT INTO interview_feedback (answer_id, relevance, clarity, structure, grammar, suggestions, overall_comments) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        relevance = VALUES(relevance),
                        clarity = VALUES(clarity),
                        structure = VALUES(structure),
                        grammar = VALUES(grammar),
                        suggestions = VALUES(suggestions),
                        overall_comments = VALUES(overall_comments)
                ");
                
                $stmtUpd->execute([
                    $lastAnswerId,
                    (int)($feedback['relevance_score'] ?? 75),
                    (int)($feedback['clarity_score'] ?? 75),
                    (int)($feedback['structure_score'] ?? 75),
                    $feedback['confidence_indication'] ?? 'Good engagement observed.',
                    json_encode($feedback['areas_to_improve'] ?? []),
                    json_encode([
                        'resume_understanding' => $feedback['resume_understanding'] ?? 'Good',
                        'project_explanation_rating' => $feedback['project_explanation_rating'] ?? 'Good',
                        'skill_explanation_rating' => $feedback['skill_explanation_rating'] ?? 'Good',
                        'hr_readiness' => $feedback['hr_readiness'] ?? 'Placement Ready',
                        'strengths' => $feedback['strengths'] ?? [],
                        'recommended_practice' => $feedback['recommended_practice'] ?? []
                    ])
                ]);
            }
        } catch (\Throwable $ex) {
            error_log("Failed to save final report feedback: " . $ex->getMessage());
        }

        return $feedback;
    }
}
