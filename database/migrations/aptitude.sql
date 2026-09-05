-- MAMCET Placement & Learning Portal - Aptitude & Streak Module Schema & Seeds
-- Database Migration for Daily Aptitude Challenge (5 questions/day) & Streak System

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Aptitude Categories
CREATE TABLE IF NOT EXISTS `aptitude_categories` (
    `category_id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(100) NOT NULL UNIQUE,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `icon` VARCHAR(50) DEFAULT 'fa-brain',
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Aptitude Question Bank
CREATE TABLE IF NOT EXISTS `aptitude_questions` (
    `question_id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT NOT NULL,
    `question_text` TEXT NOT NULL,
    `option_a` TEXT NOT NULL,
    `option_b` TEXT NOT NULL,
    `option_c` TEXT NOT NULL,
    `option_d` TEXT NOT NULL,
    `correct_option` ENUM('A', 'B', 'C', 'D') NOT NULL,
    `explanation` TEXT NOT NULL,
    `difficulty` ENUM('Easy', 'Medium', 'Hard') DEFAULT 'Medium',
    `topic` VARCHAR(100) DEFAULT 'General Aptitude',
    `company_tag` VARCHAR(255) DEFAULT 'TCS, Infosys, Wipro',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `aptitude_categories` (`category_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Daily Question Sets (5 Questions assigned per calendar date)
CREATE TABLE IF NOT EXISTS `aptitude_daily_sets` (
    `set_id` INT AUTO_INCREMENT PRIMARY KEY,
    `challenge_date` DATE NOT NULL UNIQUE,
    `question_ids` JSON NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Daily Challenge Student Progress (One record per student per day)
CREATE TABLE IF NOT EXISTS `aptitude_daily_progress` (
    `progress_id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `challenge_date` DATE NOT NULL,
    `score` INT NOT NULL DEFAULT 0,
    `total_questions` INT NOT NULL DEFAULT 5,
    `time_taken_seconds` INT NOT NULL DEFAULT 0,
    `is_completed` TINYINT(1) DEFAULT 1,
    `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_student_date` (`student_id`, `challenge_date`),
    INDEX `idx_date` (`challenge_date`),
    FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Per-Question Submissions
CREATE TABLE IF NOT EXISTS `aptitude_submissions` (
    `submission_id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `challenge_date` DATE NOT NULL,
    `question_id` INT NOT NULL,
    `selected_option` ENUM('A', 'B', 'C', 'D', 'SKIP') DEFAULT 'SKIP',
    `is_correct` TINYINT(1) DEFAULT 0,
    `time_spent_seconds` INT DEFAULT 0,
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_student_date_question` (`student_id`, `challenge_date`, `question_id`),
    FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
    FOREIGN KEY (`question_id`) REFERENCES `aptitude_questions` (`question_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Student Streaks & Lifetime Statistics
CREATE TABLE IF NOT EXISTS `aptitude_streaks` (
    `streak_id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL UNIQUE,
    `current_streak` INT NOT NULL DEFAULT 0,
    `longest_streak` INT NOT NULL DEFAULT 0,
    `last_submission_date` DATE DEFAULT NULL,
    `total_days_completed` INT NOT NULL DEFAULT 0,
    `total_questions_attempted` INT NOT NULL DEFAULT 0,
    `total_correct` INT NOT NULL DEFAULT 0,
    `accuracy_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Developer Content Import Audit
-- Content can be loaded by the repository's CLI importer without an admin UI action.
CREATE TABLE IF NOT EXISTS `aptitude_content_imports` (
    `import_id` INT AUTO_INCREMENT PRIMARY KEY,
    `source_file` VARCHAR(255) NOT NULL,
    `source_hash` CHAR(64) DEFAULT NULL,
    `actor` VARCHAR(100) NOT NULL,
    `inserted_count` INT NOT NULL DEFAULT 0,
    `updated_count` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL,
    `error_message` TEXT DEFAULT NULL,
    `imported_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_imported_at` (`imported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed Initial Categories
INSERT INTO `aptitude_categories` (`category_id`, `category_name`, `slug`, `icon`, `description`) VALUES
(1, 'Quantitative Aptitude', 'quantitative', 'fa-calculator', 'Arithmetic, algebra, geometry, percentages, profit & loss, time & work, and number problems.'),
(2, 'Logical Reasoning', 'logical', 'fa-lightbulb', 'Deductive reasoning, syllogisms, blood relations, series, coding-decoding, and pattern analysis.'),
(3, 'Verbal Ability', 'verbal', 'fa-book-open-reader', 'Grammar, vocabulary, reading comprehension, sentence completion, and critical verbal analysis.'),
(4, 'Data Interpretation', 'data-interpretation', 'fa-chart-pie', 'Charts, graphs, data sufficiency, tables, and analytical caselets.')
ON DUPLICATE KEY UPDATE `category_name` = VALUES(`category_name`);

-- Seed Rich Question Bank (60+ placement aptitude questions)
INSERT INTO `aptitude_questions` (`question_id`, `category_id`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`, `difficulty`, `topic`, `company_tag`) VALUES
-- Quantitative (Category 1)
(1, 1, 'A train running at the speed of 60 km/hr crosses a pole in 9 seconds. What is the length of the train in meters?', '120 meters', '150 meters', '180 meters', '324 meters', 'B', 'Speed = 60 * (5/18) m/sec = 50/3 m/sec. Distance = Speed * Time = (50/3) * 9 = 150 meters. Therefore, the length of the train is 150 meters.', 'Easy', 'Speed, Time & Distance', 'TCS, Infosys'),
(2, 1, 'A and B can do a piece of work in 12 days, B and C in 15 days, and C and A in 20 days. How long will A alone take to finish the work?', '20 days', '30 days', '40 days', '60 days', 'B', '2(A + B + C)\'s 1 day work = 1/12 + 1/15 + 1/20 = (5 + 4 + 3)/60 = 12/60 = 1/5. So (A + B + C)\'s 1 day work = 1/10. A\'s 1 day work = (A + B + C) - (B + C) = 1/10 - 1/15 = (3 - 2)/30 = 1/30. Thus, A alone will take 30 days.', 'Medium', 'Time & Work', 'Wipro, Accenture'),
(3, 1, 'A vendor bought toffees at 6 for a rupee. How many for a rupee must he sell to gain 20%?', '3', '4', '5', '6', 'C', 'Cost price of 6 toffees = Rs. 1. Selling price of 6 toffees to get 20% gain = 120% of 1 = Rs. 1.20 = Rs. 6/5. For Rs. 6/5, toffees sold = 6. For Re. 1, toffees sold = 6 * (5/6) = 5.', 'Easy', 'Profit & Loss', 'Cognizant, Zoho'),
(4, 1, 'The average age of a class of 30 students is 15 years. If the teacher’s age is included, the average age increases by 1 year. What is the teacher’s age?', '45 years', '46 years', '47 years', '48 years', 'B', 'Total age of 30 students = 30 * 15 = 450 years. Total age including teacher (31 people) = 31 * 16 = 496 years. Teacher\'s age = 496 - 450 = 46 years.', 'Easy', 'Averages', 'TCS, Wipro'),
(5, 1, 'In how many different ways can the letters of the word \'LEADING\' be arranged in such a way that the vowels always come together?', '360', '480', '720', '5040', 'C', 'Vowels in LEADING are E, A, I (3 vowels). Consonants are L, D, N, G (4 consonants). Treating the 3 vowels as one single group, we have 4 consonants + 1 vowel-group = 5 items. These 5 items can be arranged in 5! = 120 ways. The 3 vowels among themselves can be arranged in 3! = 6 ways. Total arrangements = 120 * 6 = 720.', 'Medium', 'Permutations & Combinations', 'Zoho, Infosys'),
(6, 1, 'Two pipes A and B can fill a tank in 20 and 30 minutes respectively. If both the pipes are used together, then how long will it take to fill the tank?', '10 minutes', '12 minutes', '15 minutes', '25 minutes', 'B', 'Part filled by A in 1 min = 1/20. Part filled by B in 1 min = 1/30. Part filled by (A + B) in 1 min = 1/20 + 1/30 = (3 + 2)/60 = 5/60 = 1/12. Hence, both pipes will take 12 minutes to fill the tank.', 'Easy', 'Pipes & Cisterns', 'TCS, Tech Mahindra'),
(7, 1, 'What is the compound interest on Rs. 25,000 for 2 years at 12% per annum, compounded annually?', 'Rs. 6,360', 'Rs. 6,000', 'Rs. 5,850', 'Rs. 6,500', 'A', 'Amount = P * (1 + R/100)^n = 25000 * (1 + 12/100)^2 = 25000 * (28/25) * (28/25) = 40 * 784 = Rs. 31,360. CI = Amount - Principal = 31360 - 25000 = Rs. 6,360.', 'Medium', 'Simple & Compound Interest', 'Accenture, Capgemini'),
(8, 1, 'Two numbers are in the ratio 3 : 5. If 9 is subtracted from each, the new numbers are in the ratio 12 : 23. What is the smaller number?', '27', '33', '49', '55', 'B', 'Let numbers be 3x and 5x. (3x - 9)/(5x - 9) = 12/23. Cross-multiplying: 23(3x - 9) = 12(5x - 9) => 69x - 207 = 60x - 108 => 9x = 99 => x = 11. Smaller number = 3x = 3 * 11 = 33.', 'Medium', 'Ratio & Proportion', 'Wipro, TCS'),
(9, 1, 'A box contains 2 white balls, 3 black balls, and 4 red balls. In how many ways can 3 balls be drawn from the box, if at least one black ball is to be included in the draw?', '32', '48', '64', '84', 'C', 'Total balls = 2 + 3 + 4 = 9. Total ways to draw 3 balls from 9 = 9C3 = (9 * 8 * 7)/(3 * 2 * 1) = 84. Ways to draw 3 balls with NO black ball (from 6 non-black balls) = 6C3 = (6 * 5 * 4)/(3 * 2 * 1) = 20. Ways with at least one black ball = 84 - 20 = 64.', 'Hard', 'Probability', 'Zoho, TCS Digital'),
(10, 1, 'A sum of money at simple interest amounts to Rs. 815 in 3 years and to Rs. 854 in 4 years. What is the sum?', 'Rs. 650', 'Rs. 690', 'Rs. 698', 'Rs. 700', 'C', 'Simple Interest for 1 year = Rs. (854 - 815) = Rs. 39. SI for 3 years = 39 * 3 = Rs. 117. Principal sum = Rs. (815 - 117) = Rs. 698.', 'Easy', 'Simple Interest', 'Infosys, Accenture'),
(11, 1, 'If 20% of a = b, then b% of 20 is the same as:', '4% of a', '5% of a', '20% of a', 'None of these', 'A', 'b = 0.2a. b% of 20 = (b / 100) * 20 = (0.2a / 100) * 20 = 0.04a = 4% of a.', 'Easy', 'Percentages', 'TCS, Cognizant'),
(12, 1, 'A car travels a distance of 170 km in 2 hours and 30 minutes. What is its speed in m/s?', '18.88 m/s', '20 m/s', '24.5 m/s', '68 m/s', 'A', 'Time = 2.5 hours. Speed = 170 / 2.5 = 68 km/hr. Converting to m/s: 68 * (5/18) = 340 / 18 = 18.88 m/s.', 'Easy', 'Speed, Time & Distance', 'Wipro, Infosys'),
(13, 1, 'The difference between simple and compound interest on Rs. 1200 for one year at 10% per annum reckoned half-yearly is:', 'Rs. 2.50', 'Rs. 3.00', 'Rs. 3.75', 'Rs. 4.00', 'B', 'Half yearly rate = 5%, n = 2 periods. CI = 1200 * (1 + 5/100)^2 - 1200 = 1200 * 1.1025 - 1200 = 1323 - 1200 = Rs. 123. SI = (1200 * 10 * 1)/100 = Rs. 120. Difference = 123 - 120 = Rs. 3.00.', 'Medium', 'Compound Interest', 'Accenture, TCS'),
(14, 1, 'A man can row upstream at 7 kmph and downstream at 10 kmph. Find man\'s rate in still water and the rate of current.', '8.5 kmph and 1.5 kmph', '9 kmph and 2 kmph', '8 kmph and 1.5 kmph', '8.5 kmph and 2 kmph', 'A', 'Speed in still water = 1/2 * (Downstream + Upstream) = 1/2 * (10 + 7) = 8.5 kmph. Rate of current = 1/2 * (Downstream - Upstream) = 1/2 * (10 - 7) = 1.5 kmph.', 'Easy', 'Boats and Streams', 'Infosys, Wipro'),
(15, 1, 'The present ages of three persons are in proportions 4 : 7 : 9. Eight years ago, the sum of their ages was 56. Find their present ages.', '16, 28, 36 years', '12, 21, 27 years', '20, 35, 45 years', 'None of these', 'A', 'Let present ages be 4x, 7x, 9x. Eight years ago: (4x - 8) + (7x - 8) + (9x - 8) = 56 => 20x - 24 = 56 => 20x = 80 => x = 4. Ages are 4*4=16, 7*4=28, 9*4=36 years.', 'Easy', 'Problems on Ages', 'TCS, Cognizant'),

-- Logical Reasoning (Category 2)
(16, 2, 'Look at this series: 2, 1, (1/2), (1/4), ... What number should come next?', '(1/3)', '(1/8)', '(2/8)', '(1/16)', 'B', 'This is a simple division series; each number is one-half of the previous number: 1/4 * 1/2 = 1/8.', 'Easy', 'Number Series', 'TCS, Infosys'),
(17, 2, 'Pointing to a photograph of a boy, Suresh said, "He is the son of the only son of my mother." How is Suresh related to that boy?', 'Brother', 'Uncle', 'Cousin', 'Father', 'D', 'The only son of Suresh\'s mother is Suresh himself. So, the boy is the son of Suresh. Thus, Suresh is the father.', 'Easy', 'Blood Relations', 'Wipro, Cognizant'),
(18, 2, 'Statements: Some actors are singers. All singers are dancers.\nConclusions:\nI. Some actors are dancers.\nII. No singer is actor.', 'Only (I) conclusion follows', 'Only (II) conclusion follows', 'Either (I) or (II) follows', 'Neither (I) nor (II) follows', 'A', 'Since some actors are singers and all singers are dancers, the actors who are singers must also be dancers. Hence, conclusion I is definitely true. Conclusion II contradicts the statement.', 'Medium', 'Syllogisms', 'Accenture, TCS'),
(19, 2, 'If in a certain language, MADRAS is coded as NBESBT, how is BOMBAY coded in that code?', 'CPNCBX', 'CPNCBZ', 'CPOCBZ', 'CQOCBZ', 'B', 'Each letter in the word is moved one step forward in the alphabetical order: M->N, A->B, D->E, R->S, A->B, S->T. Applying this to BOMBAY: B->C, O->P, M->N, B->C, A->B, Y->Z => CPNCBZ.', 'Easy', 'Coding & Decoding', 'Infosys, Wipro'),
(20, 2, 'A man walks 5 km toward South and then turns to the right. After walking 3 km he turns to the left and walks 5 km. Now in which direction is he from the starting place?', 'West', 'South', 'North-East', 'South-West', 'D', 'Starting from origin (0,0), walks 5 km south (0, -5), turns right (west) 3 km (-3, -5), turns left (south) 5 km (-3, -10). He is in the South-West quadrant relative to the start.', 'Medium', 'Direction Sense', 'TCS, Capgemini'),
(21, 2, 'Find the missing number in the sequence: 7, 26, 63, 124, 215, 342, ?', '481', '511', '391', '513', 'B', 'Pattern is (n^3 - 1): 2^3 - 1 = 7, 3^3 - 1 = 26, 4^3 - 1 = 63, 5^3 - 1 = 124, 6^3 - 1 = 215, 7^3 - 1 = 342. Next is 8^3 - 1 = 512 - 1 = 511.', 'Medium', 'Number Series', 'Zoho, TCS Digital'),
(22, 2, 'Find the odd one out: 396, 462, 572, 427, 671, 264', '396', '427', '671', '264', 'B', 'In each number except 427, the middle digit is the sum of the first and third digits: 3+6=9, 4+2=6, 5+2=7, 6+1=7, 2+4=6. For 427, 4+7 = 11 != 2. Hence 427 is the odd one.', 'Medium', 'Classification', 'Wipro, Infosys'),
(23, 2, 'Cup is to coffee as bowl is to:', 'Dish', 'Soup', 'Spoon', 'Food', 'B', 'Coffee is served/drunk from a cup; soup is served/eaten from a bowl.', 'Easy', 'Analogies', 'Cognizant, TCS'),
(24, 2, 'Five friends A, B, C, D and E are standing in a row facing north. C is to the immediate right of D. B is between A and E. E is to the immediate left of D. Who is in the middle?', 'A', 'B', 'D', 'E', 'D', 'Order from left to right: A - B - E - D - C (where B is between A and E, E is left of D, C is right of D). Middle person is E.', 'Medium', 'Seating Arrangement', 'Accenture, Zoho'),
(25, 2, 'If \'+\' means \'divided by\', \'-\' means \'multiplied by\', \'x\' means \'minus\' and \'/\' means \'plus\', then: (280 + 10 x 20) / 8 - 6 = ?', '56', '36', '48', '64', 'A', 'Replace symbols: (280 / 10 - 20) + 8 * 6 = (28 - 20) + 48 = 8 + 48 = 56.', 'Easy', 'Mathematical Operations', 'TCS, Wipro'),
(26, 2, 'Statements: All mangoes are golden in colour. No golden-coloured things are cheap.\nConclusions:\nI. All mangoes are cheap.\nII. Golden-coloured mangoes are not cheap.', 'Only conclusion I follows', 'Only conclusion II follows', 'Either I or II follows', 'Neither I nor II follows', 'B', 'Since mangoes are golden and no golden-coloured things are cheap, mangoes cannot be cheap. Therefore, Golden-coloured mangoes are not cheap (II follows).', 'Easy', 'Statement & Conclusions', 'Infosys, Accenture'),
(27, 2, 'Introducing a woman, a man said, "Her mother is the only daughter of my mother-in-law." How is the man related to the woman?', 'Father', 'Brother', 'Husband', 'Uncle', 'A', 'Mother-in-law\'s only daughter is the man\'s wife. The woman\'s mother is the man\'s wife. Therefore, the man is the father of the woman.', 'Medium', 'Blood Relations', 'TCS, Zoho'),
(28, 2, 'Find the next term in the alphanumeric series: A2C, B4D, C8E, D16F, ?', 'E32G', 'E64G', 'F32G', 'E32H', 'A', 'First letter: A, B, C, D -> E. Middle number doubles: 2, 4, 8, 16 -> 32. Third letter: C, D, E, F -> G. Thus, next term is E32G.', 'Easy', 'Alphanumeric Series', 'Cognizant, Wipro'),
(29, 2, 'A clock shows 8:30. What is the angle between the hour hand and the minute hand?', '60 degrees', '75 degrees', '80 degrees', '85 degrees', 'B', 'Angle = |30H - (11/2)M| = |30(8) - (11/2)(30)| = |240 - 165| = 75 degrees.', 'Medium', 'Clocks & Calendars', 'TCS, Infosys'),
(30, 2, 'If January 1, 2007 was a Monday, what day of the week was January 1, 2008?', 'Monday', 'Tuesday', 'Wednesday', 'Sunday', 'B', 'Year 2007 is an ordinary year (not leap). An ordinary year has 365 days = 52 weeks + 1 odd day. Hence, Jan 1, 2008 was 1 day ahead of Monday, which is Tuesday.', 'Easy', 'Clocks & Calendars', 'Wipro, Accenture'),

-- Verbal Ability (Category 3)
(31, 3, 'Choose the word that is most nearly OPPOSITE in meaning to: CANDID', 'Blunt', 'Guarded', 'Sincere', 'Outspoken', 'B', 'Candid means truthful, straightforward and frank. The opposite is guarded, secretive, or evasive.', 'Easy', 'Antonyms', 'TCS, Infosys'),
(32, 3, 'Identify the part containing an error: (A) Neither the teacher / (B) nor the students / (C) was present / (D) in the seminar hall.', '(A)', '(B)', '(C)', '(D)', 'C', 'When two subjects are joined by \'neither... nor\', the verb agrees with the subject closest to it. Since \'students\' is plural, it should be \'were present\', not \'was present\'.', 'Medium', 'Spotting Errors', 'Cognizant, Wipro'),
(33, 3, 'Select the correct meaning of the idiom: \'To burn the midnight oil\'', 'To waste resources foolishly', 'To work or study late into the night', 'To cause destruction by negligence', 'To celebrate a victory enthusiastically', 'B', '\'To burn the midnight oil\' means to work late into the night or study hard.', 'Easy', 'Idioms & Phrases', 'TCS, Accenture'),
(34, 3, 'Fill in the blank: The management team decided to ________ the proposal due to lack of budget.', 'defer', 'differ', 'deter', 'defy', 'A', '\'Defer\' means to postpone or put off to a later time. \'Differ\' means to disagree, \'deter\' means to discourage, and \'defy\' means to resist openly.', 'Easy', 'Vocabulary', 'Infosys, Wipro'),
(35, 3, 'Choose the correctly spelt word:', 'Accomodation', 'Accommodation', 'Acommodation', 'Accomadation', 'B', 'The correct spelling is \'Accommodation\' with double \'c\' and double \'m\'.', 'Easy', 'Spelling Check', 'TCS, Capgemini'),
(36, 3, 'Rearrange the sentences into a coherent paragraph:\nP: But he never gave up his dream.\nQ: Thomas Edison failed thousands of times.\nR: Today his inventions illuminate the world.\nS: He believed every failure was a step toward success.', 'QPSR', 'QSPR', 'PQRS', 'SQPR', 'B', 'Q introduces Edison\'s repeated failures. S explains his mindset towards failure. P contrasts with his perseverance. R concludes with his legacy. Logical sequence: Q-S-P-R.', 'Medium', 'Para Jumbles', 'Zoho, Accenture'),
(37, 3, 'Choose the word that best expresses the meaning of: METICULOUS', 'Careless', 'Painstakingly careful', 'Rapid', 'Hesitant', 'B', '\'Meticulous\' means showing great attention to detail; very careful and precise (painstaking).', 'Easy', 'Synonyms', 'TCS, Infosys'),
(38, 3, 'Change to Passive Voice: \'The chef prepared a sumptuous meal for the dignitaries.\'', 'A sumptuous meal is prepared by the chef for the dignitaries.', 'A sumptuous meal had been prepared by the chef for the dignitaries.', 'A sumptuous meal was prepared by the chef for the dignitaries.', 'A sumptuous meal was being prepared by the chef for the dignitaries.', 'C', 'Past simple active (\'prepared\') converts to \'was/were + past participle\' in passive voice: \'was prepared\'.', 'Easy', 'Active & Passive Voice', 'Wipro, Cognizant'),
(39, 3, 'Fill in the blank with appropriate preposition: The committee will abide ________ the guidelines issued by the regulatory authority.', 'to', 'by', 'with', 'on', 'B', 'The phrasal verb \'abide by\' means to accept or obey a rule, decision, or recommendation.', 'Easy', 'Prepositions', 'TCS, Infosys'),
(40, 3, 'Choose the one-word substitution: \'One who has an excessive enthusiasm or passion for acquiring books\'', 'Bibliophile', 'Philatelist', 'Biblioklept', 'Polyglot', 'A', 'A bibliophile is a person who collects or has a great love of books. (Philatelist collects stamps; Polyglot speaks many languages).', 'Easy', 'One Word Substitutions', 'Accenture, Zoho'),
(41, 3, 'Identify the error in: \'Despite of his illness, he attended the annual general meeting.\'', 'Despite of', 'his illness', 'he attended', 'No error', 'A', '\'Despite\' is never followed by \'of\'. Either use \'Despite his illness\' or \'In spite of his illness\'.', 'Easy', 'Spotting Errors', 'TCS, Wipro'),
(42, 3, 'Choose the antonym for: EPHEMERAL', 'Transient', 'Permanent', 'Fleeting', 'Short-lived', 'B', '\'Ephemeral\' means lasting for a very short time. The exact antonym is \'Permanent\' or \'Enduring\'.', 'Medium', 'Antonyms', 'Infosys, Zoho'),
(43, 3, 'Choose the synonym for: ADVERSITY', 'Prosperity', 'Misfortune', 'Opportunity', 'Advantage', 'B', '\'Adversity\' means difficult or unpleasant situations, hardship, or misfortune.', 'Easy', 'Synonyms', 'Cognizant, TCS'),
(44, 3, 'Complete the sentence: If I had known about the schedule change, I ________ earlier.', 'would arrive', 'would have arrived', 'will arrive', 'had arrived', 'B', 'Third conditional structure: \'If + past perfect\', followed by \'would have + past participle\'. Thus, \'would have arrived\'.', 'Medium', 'Conditional Sentences', 'Accenture, Wipro'),
(45, 3, 'Select the correct meaning of: \'Barking up the wrong tree\'', 'To accuse or pursue the wrong person or path', 'To make loud noises in public', 'To climb trees without safety equipment', 'To give up before attempting', 'A', '\'Barking up the wrong tree\' means pursuing a mistaken line of thought or accusing the wrong person.', 'Easy', 'Idioms & Phrases', 'TCS, Infosys'),

-- Data Interpretation (Category 4)
(46, 4, 'In a company, 40% of the employees are male. If 75% of the male employees earn more than Rs. 30,000 per month, and 45% of the total employees earn more than Rs. 30,000, what percentage of the female employees earn Rs. 30,000 or less?', '25%', '50%', '75%', '30%', 'C', 'Assume 100 total employees: 40 male, 60 female. Total earning > 30k = 45. Males earning > 30k = 75% of 40 = 30. Females earning > 30k = 45 - 30 = 15. Females earning <= 30k = 60 - 15 = 45. Percentage of female employees earning <= 30k = (45/60) * 100 = 75%.', 'Hard', 'Data Sufficiency & Percentages', 'TCS Digital, Zoho'),
(47, 4, 'The following table shows sales of laptops over 4 years: Year 1: 200 units, Year 2: 250 units, Year 3: 300 units, Year 4: 400 units. What is the compound annual growth rate (percentage increase) from Year 1 to Year 4?', '50%', '100%', '75%', '150%', 'B', 'Total percentage increase = ((400 - 200) / 200) * 100 = (200 / 200) * 100 = 100%.', 'Easy', 'Data Interpretation Tables', 'Infosys, Wipro'),
(48, 4, 'A pie chart shows expenditure of a family: Food 30%, Rent 25%, Education 20%, Savings 15%, Others 10%. If the family spends Rs. 15,000 on Food, what is their total monthly savings?', 'Rs. 7,500', 'Rs. 10,000', 'Rs. 12,500', 'Rs. 5,000', 'A', '30% of Total Income = 15,000 => Total Income = 15,000 / 0.30 = Rs. 50,000. Savings = 15% of 50,000 = 0.15 * 50,000 = Rs. 7,500.', 'Easy', 'Pie Charts', 'Cognizant, TCS'),
(49, 4, 'Is x greater than y? Statement 1: 2x = 3y. Statement 2: x and y are positive integers.', 'Statement 1 alone is sufficient', 'Statement 2 alone is sufficient', 'Both statements together are sufficient', 'Neither statement is sufficient', 'C', 'From Statement 1: x/y = 3/2. If x and y are positive (Statement 2), then x = 1.5y > y, so x > y is definitely true. If x and y were negative, x would be less than y. Hence both statements together are required and sufficient.', 'Medium', 'Data Sufficiency', 'TCS Digital, Accenture'),
(50, 4, 'In an examination, 70% of students passed in English, 65% in Mathematics, and 27% failed in both. If 248 students passed in both subjects, find the total number of students.', '400', '500', '600', '750', 'A', 'Percentage failed in at least one subject = 100 - Pass(both). Using union formula on pass: Pass in English = 70%, Pass in Math = 65%, Pass in at least one = 100 - 27 = 73%. Pass in both = 70 + 65 - 73 = 62%. Given 62% of total = 248 => Total = (248 * 100) / 62 = 400.', 'Hard', 'Venn Diagrams & Sets', 'Zoho, TCS'),

-- Additional Placement Aptitude Mix (51 to 60)
(51, 1, 'The speed of a boat in still water is 15 km/hr and the rate of current is 3 km/hr. The distance travelled downstream in 12 minutes is:', '1.8 km', '2.4 km', '3.6 km', '4.2 km', 'C', 'Downstream speed = 15 + 3 = 18 km/hr. Time = 12/60 = 1/5 hour. Distance = 18 * (1/5) = 3.6 km.', 'Easy', 'Boats and Streams', 'Wipro, TCS'),
(52, 1, 'A rectangular park 60 m long and 40 m wide has two concrete crossroads running in the middle of the park and rest of the park has been used as a lawn. If the width of each road is 2 m, what is the area of the lawn?', '2204 sq m', '2200 sq m', '2160 sq m', '2300 sq m', 'A', 'Area of park = 60 * 40 = 2400 sq m. Area of roads = (60 * 2) + (40 * 2) - (2 * 2) = 120 + 80 - 4 = 196 sq m. Area of lawn = 2400 - 196 = 2204 sq m.', 'Medium', 'Mensuration', 'Infosys, Accenture'),
(53, 2, 'In a certain code, MONKEY is written as XDJMNL. How is TIGER written in that code?', 'SHFDQ', 'QDFHS', 'SDFHS', 'UJHFS', 'B', 'The word is reversed and each letter is shifted -1: MONKEY reversed is YEKNOM. Y-1=X, E-1=D, K-1=J, N-1=M, O-1=N, M-1=L => XDJMNL. For TIGER: reversed is REGIT. R-1=Q, E-1=D, G-1=F, I-1=H, T-1=S => QDFHS.', 'Hard', 'Coding & Decoding', 'Zoho, TCS Digital'),
(54, 2, 'If South-East becomes North, North-East becomes West and so on, what will West become?', 'North-East', 'South-East', 'South-West', 'North-West', 'B', 'Each direction shifts 135 degrees anti-clockwise (South-East [135 deg] -> North [0 deg]). So West (270 deg) shifts 135 deg anti-clockwise to 135 deg, which is South-East.', 'Medium', 'Direction Sense', 'TCS, Wipro'),
(55, 3, 'Select the synonym for: BENEVOLENT', 'Hostile', 'Kind and generous', 'Greedy', 'Cowardly', 'B', '\'Benevolent\' means well-meaning and kindly, generous and charitable.', 'Easy', 'Synonyms', 'Accenture, Cognizant'),
(56, 3, 'Fill in the blank: Neither of the two candidates ________ suitable for the leadership role.', 'are', 'is', 'were', 'have been', 'B', '\'Neither of\' followed by a plural noun takes a singular verb (\'is\').', 'Easy', 'Subject-Verb Agreement', 'TCS, Infosys'),
(57, 1, 'A clock is started at noon. By 10 minutes past 5, the hour hand has turned through:', '145 degrees', '150 degrees', '155 degrees', '160 degrees', 'C', 'Time from 12:00 to 5:10 is 5 hours 10 minutes = 310 minutes. In 1 minute, the hour hand rotates 0.5 degrees. In 310 minutes: 310 * 0.5 = 155 degrees.', 'Medium', 'Clocks', 'Infosys, Capgemini'),
(58, 2, 'Pointing to a gentleman, Deepak said, "His only brother is the father of my daughter\'s father." How is the gentleman related to Deepak?', 'Grandfather', 'Father', 'Brother-in-law', 'Uncle', 'D', 'My daughter\'s father is Deepak himself. The father of Deepak is Deepak\'s father. The gentleman is the brother of Deepak\'s father. Hence, the gentleman is Deepak\'s uncle.', 'Medium', 'Blood Relations', 'TCS, Accenture'),
(59, 3, 'Choose the antonym for: AUDACIOUS', 'Timid', 'Courageous', 'Bold', 'Reckless', 'A', '\'Audacious\' means showing a willingness to take surprisingly bold risks. The opposite is \'Timid\' or fearful.', 'Easy', 'Antonyms', 'Zoho, Wipro'),
(60, 4, 'If average of 5 consecutive odd numbers is 61, what is the difference between the highest and lowest numbers?', '4', '8', '10', '12', 'B', 'Let consecutive odd numbers be x-4, x-2, x, x+2, x+4. Average is x = 61. Numbers are 57, 59, 61, 63, 65. Difference between highest (65) and lowest (57) is 65 - 57 = 8.', 'Easy', 'Averages & Number Properties', 'TCS, Infosys')
ON DUPLICATE KEY UPDATE `question_text` = VALUES(`question_text`);
