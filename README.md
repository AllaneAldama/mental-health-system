# 🧠 Web-Based Mental Health Assessment and Referral System

A capstone project developed for **Pateros Technological College (PTC)** that provides a secure web-based platform for conducting mental health assessments using **PHQ-9** and **GAD-7**, allowing counselors to review results and manage referrals while maintaining student confidentiality.

---

# 📌 Project Objectives

- Conduct mental health assessments using PHQ-9 and GAD-7.
- Protect student privacy by restricting assessment results to counselors only.
- Provide counselors with tools to review assessments, monitor students, and manage referrals.
- Provide administrators with user management and reporting features.
- Support desktop and mobile devices through responsive web design.

---

# 🛠️ Tech Stack

### Backend
- PHP (Procedural)
- MySQL

### Frontend
- HTML5
- CSS3
- JavaScript

### Development Environment
- XAMPP
- VS Code
- Git
- GitHub

---

# 📂 Project Structure

```
mental-health-system/
│
├── admin/
├── auth/
├── config/
├── counselor/
├── database/
├── includes/
├── student/
├── assets/
│   ├── css/
│   ├── js/
│   ├── images/
│   ├── fonts/
│   └── icons/
│
├── .gitignore
├── README.md
└── index.php
```

---

# 👨‍💻 Team Responsibilities

## Student Module
Responsible for:

- Welcome Page
- Disclaimer
- Student Information
- PHQ-9 Assessment
- GAD-7 Assessment
- Assessment Submission
- Thank You Page
- Responsive UI/UX

---

## Admin Module
Responsible for:

- Dashboard
- User Management
- Assessment Management
- Reports
- Referral Management
- System Settings

---

## Counselor Module
Responsible for:

- Dashboard
- Assessment Queue
- View Assessment
- Referral
- Monitoring
- Assessment History

---

# 📌 Shared Modules

These folders are shared by the entire development team.

```
auth/
config/
includes/
database/
```

Do not modify these files without team discussion.

---

# 🌿 Assessment Flow

```
Welcome

↓

Disclaimer

↓

Student Information

↓

PHQ-9 Assessment

↓

GAD-7 Assessment

↓

Submit Assessment

↓

Database

↓

Counselor Review

↓

Referral / Monitoring

↓

Thank You
```

---

# 🔐 Authentication

| User | Access |
|-------|--------|
| Admin | Login Required |
| Counselor | Login Required |
| Student | Assessment Link Only |

Students **cannot** view:

- Assessment Score
- Risk Classification
- Dashboard
- Assessment History

---

# 🌐 Responsive Design

The system is designed to work on:

- Desktop
- Laptop
- Tablet
- Mobile Devices

---

# 🌱 Git Workflow

Main Branch

```
main
```

Development Branches

```
student-module
admin-module
counselor-module
```

Each developer must work only on their assigned branch.

---

# 📝 Commit Message Format

Examples:

```
feat: Add student welcome page

feat: Create counselor dashboard

fix: Fix login authentication

style: Improve responsive layout

refactor: Optimize assessment logic
```

---

# 🚀 Getting Started

## 1. Clone Repository

```bash
git clone https://github.com/AllaneAldama/mental-health-system.git
```

---

## 2. Open Project

```bash
cd mental-health-system
```

---

## 3. Create Your Branch

Student Module

```bash
git checkout -b student-module
```

Admin Module

```bash
git checkout -b admin-module
```

Counselor Module

```bash
git checkout -b counselor-module
```

---

## 4. Push Branch

Example

```bash
git push -u origin student-module
```

---

# 📌 Daily Git Commands

```
git status

git add .

git commit -m "Describe your changes"

git push
```

---

# 📖 Development Guidelines

- Do not commit directly to **main**.
- Work only on your assigned module.
- Keep commit messages meaningful.
- Test your module before pushing.
- Discuss changes to shared folders before modifying them.

---

# 📄 License

This project is developed solely for educational and academic purposes as a Capstone Project of Pateros Technological College.

---

## 👥 Development Team

| Module | Developer |
|----------|-----------|
| Student Module | |
| Admin Module | |
| Counselor Module | |

---

## ❤️ Built with dedication by the Capstone Team
