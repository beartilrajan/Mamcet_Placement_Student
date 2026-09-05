<?php
// MAMCET Placement & Learning Portal - Job Seeder Service

class JobSeederService {
    public static function seedIfEmpty($db): void {
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM job_descriptions");
            $count = $stmt ? (int)$stmt->fetchColumn() : 0;
            if ($count === 0) {
                self::seed($db);
            }
        } catch (Exception $e) {
            // Silently skip if table not created yet
        }
    }

    public static function seed($db): int {
        // Fetch active session or first session
        $sessionId = 1;
        try {
            $stmtSess = $db->query("SELECT session_id FROM academic_sessions WHERE is_active = 1 LIMIT 1");
            $fetchedSess = $stmtSess ? $stmtSess->fetchColumn() : false;
            if ($fetchedSess) {
                $sessionId = (int)$fetchedSess;
            } else {
                $stmtFirst = $db->query("SELECT session_id FROM academic_sessions ORDER BY session_id ASC LIMIT 1");
                $firstSess = $stmtFirst ? $stmtFirst->fetchColumn() : false;
                if ($firstSess) $sessionId = (int)$firstSess;
            }
        } catch (Exception $e) {
            $sessionId = 1;
        }

        $jobs = [
            [
                'company_name' => 'Zoho Corporation',
                'job_title' => 'Software Development Engineer (SDE)',
                'job_role' => 'Product Developer',
                'employment_type' => 'Full-Time',
                'package_lpa' => 8.50,
                'job_location' => 'Chennai / Tenkasi',
                'work_mode' => 'On-Site',
                'job_summary' => 'Design, develop, and maintain high-performance web applications, scalable distributed backends, and rich interactive user interfaces for Zoho SaaS products.',
                'responsibilities' => 'Write clean, maintainable Java/C++/Python code. Optimize complex database queries and APIs. Collaborate with product managers and QA teams to ship scalable features.',
                'required_skills' => 'Java, C++, Data Structures, Algorithms, OOPs, SQL, REST APIs, Problem Solving',
                'preferred_skills' => 'React, Node.js, Linux, Git, System Design, Multithreading',
                'min_cgpa' => 7.00,
                'max_standing_arrears' => 0,
                'max_history_arrears' => 1,
                'graduation_year' => (int)date('Y') + 1,
                'application_deadline' => date('Y-m-d', strtotime('+60 days')),
                'drive_date' => date('Y-m-d', strtotime('+75 days')),
                'status' => 'published'
            ],
            [
                'company_name' => 'Tata Consultancy Services (TCS)',
                'job_title' => 'Digital Innovator / Systems Engineer',
                'job_role' => 'Software Engineer',
                'employment_type' => 'Full-Time',
                'package_lpa' => 7.00,
                'job_location' => 'Chennai / Bangalore / Hyderabad',
                'work_mode' => 'Hybrid',
                'job_summary' => 'Build modern enterprise cloud applications, full-stack microservices, and AI-driven automation pipelines for global digital transformation clients.',
                'responsibilities' => 'Develop and deploy full-stack web modules. Write unit tests, automate CI/CD pipelines, and support agile sprint deliverables with clean documentation.',
                'required_skills' => 'Java, Python, SQL, HTML, CSS, JavaScript, Spring Boot, Git',
                'preferred_skills' => 'AWS, Docker, Microservices, Angular, Jenkins, Agile',
                'min_cgpa' => 6.50,
                'max_standing_arrears' => 0,
                'max_history_arrears' => 2,
                'graduation_year' => (int)date('Y') + 1,
                'application_deadline' => date('Y-m-d', strtotime('+45 days')),
                'drive_date' => date('Y-m-d', strtotime('+60 days')),
                'status' => 'published'
            ],
            [
                'company_name' => 'Infosys',
                'job_title' => 'Specialist Programmer (SP)',
                'job_role' => 'Full Stack Developer',
                'employment_type' => 'Full-Time',
                'package_lpa' => 9.50,
                'job_location' => 'Bangalore / Mysore / Chennai',
                'work_mode' => 'Hybrid',
                'job_summary' => 'High-impact technical developer role focusing on complex algorithmic problem solving, modern web architectures, and full-stack software development.',
                'responsibilities' => 'Implement end-to-end features across front-end and back-end stacks. Optimize low-latency services, databases, and third-party integrations.',
                'required_skills' => 'Python, Java, React.js, Node.js, MySQL, Data Structures, Algorithms',
                'preferred_skills' => 'GraphQL, TypeScript, Kubernetes, Cloud Architecture, Redis',
                'min_cgpa' => 7.00,
                'max_standing_arrears' => 0,
                'max_history_arrears' => 0,
                'graduation_year' => (int)date('Y') + 1,
                'application_deadline' => date('Y-m-d', strtotime('+50 days')),
                'drive_date' => date('Y-m-d', strtotime('+65 days')),
                'status' => 'published'
            ],
            [
                'company_name' => 'Amazon',
                'job_title' => 'Software Development Engineer (Intern)',
                'job_role' => 'SDE Intern',
                'employment_type' => 'Internship',
                'package_lpa' => 12.00,
                'job_location' => 'Chennai / Bangalore / Hyderabad',
                'work_mode' => 'On-Site',
                'job_summary' => 'Work on large-scale distributed systems, high-volume transactional services, and customer-facing features across Amazon AWS and Retail platforms.',
                'responsibilities' => 'Design, test, and deploy resilient scalable services. Deep-dive into distributed system performance and scalability bottlenecks.',
                'required_skills' => 'Java, C++, Object-Oriented Design, Data Structures, Algorithms, Distributed Systems, SQL',
                'preferred_skills' => 'AWS (S3, DynamoDB, Lambda), Linux, Python, Multithreading, CI/CD',
                'min_cgpa' => 7.50,
                'max_standing_arrears' => 0,
                'max_history_arrears' => 0,
                'graduation_year' => (int)date('Y') + 1,
                'application_deadline' => date('Y-m-d', strtotime('+40 days')),
                'drive_date' => date('Y-m-d', strtotime('+55 days')),
                'status' => 'published'
            ],
            [
                'company_name' => 'Wipro Technologies',
                'job_title' => 'Turbo Engineer',
                'job_role' => 'Cloud & DevOps Specialist',
                'employment_type' => 'Full-Time',
                'package_lpa' => 6.50,
                'job_location' => 'Bangalore / Chennai / Pune',
                'work_mode' => 'Hybrid',
                'job_summary' => 'Design and manage cloud infrastructure, automate deployment pipelines, and configure secure containerized applications.',
                'responsibilities' => 'Manage AWS/Azure cloud resources. Build automated CI/CD workflows using GitHub Actions and Docker. Monitor system health and uptime.',
                'required_skills' => 'Linux, Python, Bash Scripting, Docker, Git, CI/CD, Networking, AWS Basics',
                'preferred_skills' => 'Kubernetes, Terraform, Prometheus, Grafana, Ansible',
                'min_cgpa' => 6.50,
                'max_standing_arrears' => 0,
                'max_history_arrears' => 1,
                'graduation_year' => (int)date('Y') + 1,
                'application_deadline' => date('Y-m-d', strtotime('+35 days')),
                'drive_date' => date('Y-m-d', strtotime('+50 days')),
                'status' => 'published'
            ],
            [
                'company_name' => 'Cognizant (CTS)',
                'job_title' => 'GenC Next Developer',
                'job_role' => 'AI & Full Stack Engineer',
                'employment_type' => 'Full-Time',
                'package_lpa' => 6.75,
                'job_location' => 'Chennai / Coimbatore / Bangalore',
                'work_mode' => 'On-Site',
                'job_summary' => 'Develop modern AI-assisted web applications, integrate machine learning APIs, and build responsive user experiences for enterprise clients.',
                'responsibilities' => 'Develop front-end components and RESTful microservices. Integrate AI models and automated workflow routines.',
                'required_skills' => 'JavaScript, React.js, Python, Flask/FastAPI, SQL, REST APIs, HTML5/CSS3',
                'preferred_skills' => 'OpenAI/Gemini API, Tailwind CSS, PostgreSQL, MongoDB',
                'min_cgpa' => 6.50,
                'max_standing_arrears' => 0,
                'max_history_arrears' => 1,
                'graduation_year' => (int)date('Y') + 1,
                'application_deadline' => date('Y-m-d', strtotime('+30 days')),
                'drive_date' => date('Y-m-d', strtotime('+45 days')),
                'status' => 'published'
            ],
            [
                'company_name' => 'Accenture',
                'job_title' => 'Advanced App Engineering Analyst',
                'job_role' => 'Software Analyst',
                'employment_type' => 'Full-Time',
                'package_lpa' => 6.50,
                'job_location' => 'Chennai / Bangalore / Hyderabad',
                'work_mode' => 'Hybrid',
                'job_summary' => 'Design and deliver technology solutions utilizing modern programming languages, cloud frameworks, and database technologies.',
                'responsibilities' => 'Participate in design reviews, develop secure application modules, and perform code optimizations.',
                'required_skills' => 'Java, C#, .NET / Spring Boot, SQL, Object Oriented Analysis, Git',
                'preferred_skills' => 'Azure Cloud, Agile Methodologies, Automated Testing (Selenium/JUnit)',
                'min_cgpa' => 6.50,
                'max_standing_arrears' => 0,
                'max_history_arrears' => 1,
                'graduation_year' => (int)date('Y') + 1,
                'application_deadline' => date('Y-m-d', strtotime('+50 days')),
                'drive_date' => date('Y-m-d', strtotime('+65 days')),
                'status' => 'published'
            ],
            [
                'company_name' => 'Kaar Technologies',
                'job_title' => 'Associate SAP Consultant',
                'job_role' => 'Enterprise Consultant',
                'employment_type' => 'Full-Time',
                'package_lpa' => 8.00,
                'job_location' => 'Chennai / Trichy',
                'work_mode' => 'On-Site',
                'job_summary' => 'Configure and develop SAP ERP business applications, custom ABAP workflows, and enterprise integration solutions.',
                'responsibilities' => 'Develop business logic in SAP environments. Analyze customer requirements, test configurations, and document user flows.',
                'required_skills' => 'C++, Java, DBMS, SQL, Software Engineering Principles, Business Logic',
                'preferred_skills' => 'SAP ABAP, ERP fundamentals, Web Dynpro, Cloud Integration',
                'min_cgpa' => 7.00,
                'max_standing_arrears' => 0,
                'max_history_arrears' => 0,
                'graduation_year' => (int)date('Y') + 1,
                'application_deadline' => date('Y-m-d', strtotime('+60 days')),
                'drive_date' => date('Y-m-d', strtotime('+75 days')),
                'status' => 'published'
            ]
        ];

        // Detect available columns in job_descriptions table
        $hasSessionId = false;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM job_descriptions LIKE 'session_id'");
            $hasSessionId = ($colCheck && $colCheck->rowCount() > 0);
        } catch (Exception $e) {
            $hasSessionId = false;
        }

        if ($hasSessionId) {
            $stmt = $db->prepare("
                INSERT INTO job_descriptions (
                    company_name, job_title, job_role, employment_type, package_lpa, 
                    job_location, work_mode, job_summary, responsibilities, 
                    required_skills, preferred_skills, min_cgpa, max_standing_arrears, 
                    max_history_arrears, graduation_year, application_deadline, drive_date, 
                    session_id, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
        } else {
            $stmt = $db->prepare("
                INSERT INTO job_descriptions (
                    company_name, job_title, job_role, employment_type, package_lpa, 
                    job_location, work_mode, job_summary, responsibilities, 
                    required_skills, preferred_skills, min_cgpa, max_standing_arrears, 
                    max_history_arrears, graduation_year, application_deadline, drive_date, 
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
        }

        $insertedCount = 0;
        foreach ($jobs as $j) {
            try {
                if ($hasSessionId) {
                    $stmt->execute([
                        $j['company_name'],
                        $j['job_title'],
                        $j['job_role'],
                        $j['employment_type'],
                        $j['package_lpa'],
                        $j['job_location'],
                        $j['work_mode'],
                        $j['job_summary'],
                        $j['responsibilities'],
                        $j['required_skills'],
                        $j['preferred_skills'],
                        $j['min_cgpa'],
                        $j['max_standing_arrears'],
                        $j['max_history_arrears'],
                        $j['graduation_year'],
                        $j['application_deadline'],
                        $j['drive_date'],
                        $sessionId,
                        $j['status']
                    ]);
                } else {
                    $stmt->execute([
                        $j['company_name'],
                        $j['job_title'],
                        $j['job_role'],
                        $j['employment_type'],
                        $j['package_lpa'],
                        $j['job_location'],
                        $j['work_mode'],
                        $j['job_summary'],
                        $j['responsibilities'],
                        $j['required_skills'],
                        $j['preferred_skills'],
                        $j['min_cgpa'],
                        $j['max_standing_arrears'],
                        $j['max_history_arrears'],
                        $j['graduation_year'],
                        $j['application_deadline'],
                        $j['drive_date'],
                        $j['status']
                    ]);
                }
                $insertedCount++;
            } catch (Exception $e) {
                // Silently skip duplicate or failed single row
            }
        }

        return $insertedCount;
    }
}
