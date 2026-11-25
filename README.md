Nursing Basic Course – Learning Management System

A web-based Learning Management System (LMS) designed to support nursing education through structured courses, interactive learning tools, student management, and administrative operations.

The platform provides dedicated experiences for students, instructors, and administrators, combining educational content delivery with centralized platform management.

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

7. Assessments

"Assessments" (docs/screenshots/07-assessments.png)

8. Certificates

"Certificates" (docs/screenshots/08-certificates.png)

9. Mobile Responsive Interface

"Mobile Responsive" (docs/screenshots/09-mobile-responsive.png)

✨ Key Features

Student Portal

- Student registration and authentication.
- Personalized student dashboard.
- Course browsing and enrollment.
- Video-based educational content.
- Learning progress tracking.
- Online assessments and quizzes.
- Digital certificates and verification.
- PDF educational resources.
- Student profile management.
- Notifications and support.

Admin Portal

- Dedicated administrative authentication.
- Centralized dashboard.
- Course and lesson management.
- Student account management.
- Instructor management.
- Assessment and quiz management.
- Certificate management.
- Announcements and notifications.
- Platform settings and content management.

Instructor Features

- Instructor authentication.
- Educational content management.
- Assessment creation and management.
- AI-assisted assessment tools.
- Course-related administrative operations.

Additional Capabilities

- Role-based access control.
- Separate authentication sessions for different user roles.
- Database-driven content management.
- Responsive user interface.
- AI integration using Google Gemini.
- Course media and file management.

🛠️ Technologies Used

Technology| Purpose
PHP| Backend development
MySQL| Database management
JavaScript| Interactive functionality
HTML5| Web structure
CSS3| Responsive interface
PDO| Secure database access
Git & GitHub| Version control

🏗️ Project Structure

Nursing-Basic-Course/
│
├── api/
│   ├── admin/
│   ├── auth/
│   ├── config/
│   ├── instructor/
│   ├── student/
│   └── public/
│
├── asset/
├── auth/
├── database/
│   └── migrations/
│
├── docs/
│   └── screenshots/
│
├── edu-nursing/
├── admin.html
├── instructor.html
├── student.html
├── index.html
├── login.html
├── .htaccess
├── .gitignore
└── README.md

⚙️ Local Installation

Requirements

- PHP 8.0 or later.
- MySQL.
- Apache Server.
- PHP PDO MySQL extension.
- PHP cURL extension (for AI integration).

Setup

1. Clone the repository:

git clone https://github.com/ay6038776-collab/Nursing-Basic-Course.git

2. Move the project into your local server directory:

C:\xampp\htdocs\NursingBasicCourse

3. Create a MySQL database named:

nursing_basic_course

4. Import the required SQL migration files from:

database/migrations/

5. Configure your local database connection in:

api/config/database.php

6. Configure the Gemini API environment variables if AI features are required:

NBC_GEMINI_API_KEY=your_api_key
NBC_GEMINI_MODEL=your_model

7. Start Apache and MySQL through XAMPP.

8. Open the project in your browser:

http://localhost/NursingBasicCourse/

🔐 Security

Security considerations implemented in the project include:

- Separate authentication flows for different user roles.
- Server-side authentication and authorization checks.
- PDO prepared statements for database operations.
- Rate-limiting support.
- Protected configuration and upload directories.
- Sensitive local files excluded from version control.

Important: Never publish database credentials, API keys, private uploads, or real student information.

🎯 Project Goals

The project focuses on building a practical educational platform that combines backend development, database architecture, authentication, access control, and modern web interfaces.

It demonstrates the implementation of a multi-role educational system with real-world platform management requirements.

👨‍💻 Developer

Ahmad Yasser

Backend Development | PHP & MySQL | Web Application Development

GitHub: https://github.com/ay6038776-collab

---

Developed as a practical web application project for nursing education and digital learning management.
