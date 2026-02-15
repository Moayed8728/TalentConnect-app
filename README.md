# TalentConnect Application

1. Introduction

    TalentConnect is a Laravel-based recruitment platform that allows candidates to apply for jobs, upload resumes, and receive AI-powered feedback. The system extracts resume content from PDF files using pdftotext and analyzes it using Google Gemini AI.

2. Tech Stack

    1. Laravel 12
    2. PHP 8.3+
    3. MariaDB
    4. Docker
    5. Google Gemini API
    6. Vite for frontend assets

3. System Architecture

    1. User uploads resume PDF.
    2. The application stores the file locally.
    3. A Docker-based pdftotext service extracts text from the PDF.
    4. Extracted content is sent to Gemini AI.
    5. AI feedback is stored and displayed to the user.

4. Installation

    1. Clone the repository.
    2. Run composer install.
    3. Copy .env.example to .env.
    4. Configure database credentials.
    5. Configure GEMINI_API_KEY.
    6. Run php artisan key:generate.
    7. Run php artisan migrate.
    8. Start Docker containers.
    9. Run php artisan serve --port=8001.

5. Environment Variables

    1. DB_HOST
    2. DB_PORT
    3. DB_DATABASE
    4. DB_USERNAME
    5. DB_PASSWORD
    6. GEMINI_API_KEY
    7. GEMINI_MODEL

6. Docker Services

    1. MariaDB runs on port 3307.
    2. pdftotext service runs inside Docker container.
    3. phpMyAdmin is available for database inspection.

7. AI Resume Analysis Flow

    1. Resume is saved in storage.
    2. Docker container converts PDF to text.
    3. ResumeAnalysisService sends extracted text to Gemini.
    4. AI score and feedback are returned.
    5. Feedback is stored in database and displayed.

8. Development Notes

    1. Docker must be running locally.
    2. MariaDB container uses restart policy always.
    3. Laravel server must be started manually.
    4. bootstrap/cache and storage directories must be writable.

9. License

This project is for educational and development purposes.
