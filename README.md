🩺 Nursing Basic Course – Edu Nursing LMS

A modern, full-featured Nursing Learning Management System (LMS) designed to provide an organized digital learning experience for nursing students, instructors, and administrators.

The platform combines educational content, course management, student tracking, assessments, and administrative tools in one integrated system.

🌐 Live Demo

- Live Platform: https://nursingbasiccourse.liveblog365.com/
- GitHub Repository: https://github.com/ay6038776-collab/Nursing-Basic-Course

📸 Screenshots

Explore the platform's interface and main features.

1. Homepage

"Homepage" (docs/screenshots/01-homepage.png)

2. Admin Dashboard

"Admin Dashboard" (docs/screenshots/02-admin-dashboard.png)

3. Course Management

"Course Management" (docs/screenshots/03-course-management.png)

4. Lesson Management

"Lesson Management" (docs/screenshots/04-lesson-management.png)

5. Student Dashboard

"Student Dashboard" (docs/screenshots/05-student-dashboard.png)

6. Student Courses

"Student Courses" (docs/screenshots/06-student-courses.png)

7. Instructor Dashboard

"Instructor Dashboard" (docs/screenshots/07-instructor-dashboard.png)

8. Assessment System

"Assessment System" (docs/screenshots/08-assessments.png)

9. Student Portal

"Student Portal" (docs/screenshots/09-student-portal.png)

✨ Key Features

🎓 Learning Management

- Course and lesson management.
- Structured educational content.
- Video-based learning.
- Course enrollment and student access.
- Learning progress tracking.

👨‍🎓 Student Portal

- Personalized student dashboard.
- Registered courses.
- Learning progress.
- Clinical assessments and quizzes.
- Digital certificates.
- Student profile management.

👨‍🏫 Instructor Portal

- Instructor dashboard.
- Course and lesson management.
- Student assessment tools.
- Educational content management.
- AI-powered educational assistance.

🛡️ Administration

- Dedicated administration dashboard.
- Student and instructor management.
- Course management.
- Enrollment and payment management.
- Announcements and notifications.
- Platform settings and reports.

🤖 AI Integration

- AI-powered educational assistance.
- AI assessment generation.
- AI-assisted grading.
- Interactive academic assistant.

📱 User Experience

- Responsive interface.
- Arabic and English language support.
- Modern UI design.
- Mobile-friendly layouts.
- Organized navigation.

🛠️ Technologies Used

Technology| Purpose
PHP| Backend Development
MySQL| Database Management
JavaScript| Interactive Functionality
HTML5| Structure
CSS3| Styling and Responsive Design
PDO| Secure Database Operations
REST APIs| Backend Communication
Gemini API| AI Integration
Git & GitHub| Version Control

🏗️ System Architecture

The platform follows a modular PHP backend structure with separated application areas:

- "api/" – Backend endpoints and API logic.
- "api/admin/" – Administrative operations.
- "api/student/" – Student services.
- "api/instructor/" – Instructor services.
- "api/auth/" – Authentication.
- "api/config/" – Database, session, and configuration.
- "database/" – Database migrations and SQL scripts.
- "asset/" – Frontend assets.
- "auth/" – Authentication interfaces.
- "edu-nursing/" – Educational platform components.

🔐 Security & Access Control

- Role-based access control.
- Separate authentication flows for different user roles.
- Session management and isolation.
- PDO prepared statements.
- API access protection.
- Upload restrictions and validation.
- Rate limiting for supported endpoints.

🚀 Installation

Requirements

- PHP 8.1 or newer.
- MySQL.
- Apache Server.
- PHP PDO MySQL extension.
- cURL extension.

Local Setup

1. Clone the repository:
   
   git clone https://github.com/ay6038776-collab/Nursing-Basic-Course.git

2. Move the project into your local server directory.

3. Create a MySQL database named:
   
   nursing_basic_course

4. Import the database schema and migration files.

5. Configure your database connection in:
   
   api/config/database.php

6. Configure the required environment variables for external services.

7. Start Apache and MySQL through XAMPP.

8. Open the project in your browser.

👨‍💻 Developer

Ahmad Yasser

Backend Developer | Software Development & AI Enthusiast

GitHub: https://github.com/ay6038776-collab

---

Developed as an integrated educational platform focused on improving digital nursing education and learning management.
