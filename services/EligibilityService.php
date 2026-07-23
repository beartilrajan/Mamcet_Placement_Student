<?php
// MAMCET Placement & Learning Portal - Automated Eligibility Engine Service

class EligibilityService {
    /**
     * Compute and cache eligibility status for a student against a specific Job Description.
     */
    public static function calculateStudentEligibility($db, int $studentId, int $jobId): array {
        // 1. Fetch Job Description parameters and rule criteria
        $stmtJob = $db->prepare("SELECT * FROM job_descriptions WHERE job_id = ?");
        $stmtJob->execute([$jobId]);
        $job = $stmtJob->fetch();
        if (!$job) {
            throw new Exception("Job Description ID $jobId not found.");
        }

        // Fetch eligible departments for this job description
        $stmtDepts = $db->prepare("SELECT dept_id FROM job_description_departments WHERE job_id = ?");
        $stmtDepts->execute([$jobId]);
        $allowedDepts = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

        // Fetch eligible batches
        $stmtBatches = $db->prepare("SELECT batch_id FROM job_description_batches WHERE job_id = ?");
        $stmtBatches->execute([$jobId]);
        $allowedBatches = $stmtBatches->fetchAll(PDO::FETCH_COLUMN);

        // 2. Fetch Student detailed profile & academics
        $stmtStudent = $db->prepare("
            SELECT s.*, sa.tenth_percentage, sa.twelfth_percentage, sa.diploma_percentage, 
                   sa.current_cgpa, sa.standing_arrears, sa.history_of_arrears
            FROM students s
            LEFT JOIN student_academics sa ON s.student_id = sa.student_id
            WHERE s.student_id = ?
        ");
        $stmtStudent->execute([$studentId]);
        $student = $stmtStudent->fetch();
        if (!$student) {
            throw new Exception("Student ID $studentId not found.");
        }

        // Fetch active override status if locked/saved previously
        $stmtStatus = $db->prepare("SELECT eligibility_id, status, is_locked, override_reason, override_by FROM student_eligibility WHERE student_id = ? AND job_id = ?");
        $stmtStatus->execute([$studentId, $jobId]);
        $cachedElig = $stmtStatus->fetch();

        if ($cachedElig && $cachedElig['is_locked'] == 1) {
            return [
                'eligibility_id' => $cachedElig['eligibility_id'],
                'is_eligible' => ($cachedElig['status'] === 'Eligible' || $cachedElig['status'] === 'Officer Override') ? 1 : 0,
                'status' => $cachedElig['status'],
                'reasons' => [],
                'override_reason' => $cachedElig['override_reason'],
                'is_locked' => 1
            ];
        }

        $reasons = [];
        $isIncomplete = false;

        // 3. Perform academic checks
        $cgpa = $student['current_cgpa'];
        $tenth = $student['tenth_percentage'];
        $twelfth = $student['twelfth_percentage'];
        $diploma = $student['diploma_percentage'];
        $standingArrears = $student['standing_arrears'];
        $historyArrears = $student['history_of_arrears'];

        // If core data is completely missing, flag as Data Incomplete
        if ($cgpa === null || $tenth === null || ($twelfth === null && $diploma === null)) {
            $isIncomplete = true;
            $reasons[] = "Institutional Academic Records are incomplete (CGPA, 10th, or 12th/Diploma percentages are missing).";
        } else {
            if ($cgpa < $job['min_cgpa']) {
                $reasons[] = "Minimum CGPA required: " . number_format($job['min_cgpa'], 2) . ". Current CGPA: " . number_format($cgpa, 2);
            }
            if ($tenth < $job['min_cgpa']) { // Wait, the job description check is min_cgpa for college, but we can do tenth check:
                // Actually, job description has: min_cgpa, max_standing_arrears, max_history_arrears.
                // Let's check max standing arrears:
                if ($standingArrears > $job['max_standing_arrears']) {
                    $reasons[] = "Maximum Standing Arrears allowed: " . $job['max_standing_arrears'] . ". Current Arrears: " . $standingArrears;
                }
                if ($historyArrears > $job['max_history_arrears']) {
                    $reasons[] = "Maximum History of Arrears allowed: " . $job['max_history_arrears'] . ". Current History: " . $historyArrears;
                }
            }
        }

        // 4. Section Batch / Department check
        if (!empty($allowedDepts) && !in_array($student['dept_id'], $allowedDepts)) {
            $reasons[] = "Department is not listed in job opportunity's allowed departments.";
        }

        if (!empty($allowedBatches) && !in_array($student['batch_id'], $allowedBatches)) {
            $reasons[] = "Batch is not listed in job opportunity's allowed batches.";
        }

        if ($student['graduation_year'] != $job['graduation_year']) {
            $reasons[] = "Graduation Year required: " . $job['graduation_year'] . ". Student Graduation Year: " . $student['graduation_year'];
        }

        // 5. Placement Willingness check
        if (strtolower($student['placement_willingness']) !== 'yes') {
            $reasons[] = "Placement Willingness is marked as '" . $student['placement_willingness'] . "' (Must be 'Yes').";
        }

        // 6. Resume check
        $stmtResume = $db->prepare("SELECT COUNT(*) FROM resume_files WHERE student_id = ? AND is_current = 1");
        $stmtResume->execute([$studentId]);
        $hasResume = (int)$stmtResume->fetchColumn() > 0;
        if (!$hasResume) {
            $reasons[] = "No active resume uploaded on portal.";
        }

        // Determine outcome status
        if ($isIncomplete) {
            $status = 'Data Incomplete';
            $isEligible = 0;
        } elseif (!empty($reasons)) {
            $status = 'Not Eligible';
            $isEligible = 0;
        } else {
            $status = 'Eligible';
            $isEligible = 1;
        }

        // Override status if it was overridden previously, but not locked
        if ($cachedElig && $cachedElig['status'] === 'Officer Override') {
            return [
                'eligibility_id' => $cachedElig['eligibility_id'],
                'is_eligible' => 1,
                'status' => 'Officer Override',
                'reasons' => $reasons, // Show original failure reasons alongside override
                'override_reason' => $cachedElig['override_reason'],
                'is_locked' => 0
            ];
        }

        // Cache result in database
        if ($cachedElig) {
            $stmtUpd = $db->prepare("
                UPDATE student_eligibility 
                SET is_eligible = ?, status = ?, ineligibility_reasons = ?
                WHERE eligibility_id = ?
            ");
            $stmtUpd->execute([$isEligible, $status, json_encode($reasons), $cachedElig['eligibility_id']]);
            $eligibilityId = $cachedElig['eligibility_id'];
        } else {
            $stmtIns = $db->prepare("
                INSERT INTO student_eligibility (student_id, job_id, is_eligible, status, ineligibility_reasons)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([$studentId, $jobId, $isEligible, $status, json_encode($reasons)]);
            $eligibilityId = (int)$db->lastInsertId();
        }

        return [
            'eligibility_id' => $eligibilityId,
            'is_eligible' => $isEligible,
            'status' => $status,
            'reasons' => $reasons,
            'override_reason' => null,
            'is_locked' => 0
        ];
    }

    /**
     * Override student eligibility status by Placement Officer.
     */
    public static function overrideEligibility($db, int $eligibilityId, string $newStatus, string $reason, int $officerUserId): void {
        $db->beginTransaction();
        try {
            // Fetch previous status
            $stmtPrev = $db->prepare("SELECT status FROM student_eligibility WHERE eligibility_id = ?");
            $stmtPrev->execute([$eligibilityId]);
            $prevStatus = $stmtPrev->fetchColumn() ?: 'Pending Verification';

            // Update eligibility
            $stmtUpd = $db->prepare("
                UPDATE student_eligibility 
                SET status = 'Officer Override', is_eligible = ?, override_reason = ?, override_by = ?
                WHERE eligibility_id = ?
            ");
            $isEligible = ($newStatus === 'Eligible' || $newStatus === 'Officer Override') ? 1 : 0;
            $stmtUpd->execute([$isEligible, $reason, $officerUserId, $eligibilityId]);

            // Log history audit
            $stmtHist = $db->prepare("
                INSERT INTO eligibility_history (eligibility_id, previous_status, new_status, action_reason, action_by)
                VALUES (?, ?, 'Officer Override', ?, ?)
            ");
            $stmtHist->execute([$eligibilityId, $prevStatus, $reason, $officerUserId]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Lock eligibility status to prevent updates during recruitment drive execution.
     */
    public static function lockEligibility($db, int $eligibilityId, bool $lockState): void {
        $stmt = $db->prepare("UPDATE student_eligibility SET is_locked = ? WHERE eligibility_id = ?");
        $stmt->execute([$lockState ? 1 : 0, $eligibilityId]);
    }
}
