<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome Back</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #f3f2f0;
            color: #1a1a1a;
        }

        body {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow: hidden;
        }

        .bg-shape {
            position: absolute;
            width: 520px;
            height: 520px;
            border-radius: 50%;
            background: rgba(143, 181, 144, 0.18);
            z-index: 0;
        }

        .bg-shape.left {
            left: -130px;
            bottom: -60px;
        }

        .bg-shape.right {
            right: -130px;
            top: -80px;
        }

        .login-shell {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 90px;
            width: min(1200px, 90vw);
        }

        .illustration-wrap {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 520px;
        }

        .brain-illustration {
            width: min(440px, 42vw);
            min-width: 260px;
            filter: drop-shadow(0 10px 20px rgba(89, 120, 92, 0.08));
        }

        .login-card {
            width: min(500px, 42vw);
            min-width: 320px;
            background: rgba(255, 255, 255, 0.32);
            border: 1px solid rgba(130, 145, 133, 0.25);
            border-radius: 14px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.04);
            padding: 38px 34px 32px;
        }

        .login-title {
            margin: 0 0 8px;
            font-size: clamp(2.2rem, 2vw, 3rem);
            font-weight: 800;
            letter-spacing: -0.06em;
            text-align: center;
            color: #121212;
        }

        .login-subtitle {
            margin: 0 0 24px;
            text-align: center;
            color: #4a4a4a;
            font-size: 1.05rem;
            font-weight: 400;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .field {
            display: flex;
            flex-direction: column;
        }

        .input-wrap {
            position: relative;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            height: 52px;
            border: 1px solid #b8b8b8;
            border-radius: 8px;
            background: rgba(255,255,255,0.2);
            font-size: 1.05rem;
            padding: 0 16px;
            color: #1a1a1a;
            outline: none;
        }

        input::placeholder {
            color: #707070;
        }

        input:focus {
            border-color: #7f9b82;
            box-shadow: 0 0 0 3px rgba(124, 158, 127, 0.12);
        }

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 1.1rem;
            color: #5d5d5d;
        }

        .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: -4px;
        }

        .remember {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: #2f2f2f;
            font-size: 0.98rem;
        }

        .remember input {
            width: 16px;
            height: 16px;
            accent-color: #5c8a60;
        }

        .forgot {
            color: #2e2e2e;
            text-decoration: none;
            font-size: 0.94rem;
        }

        .primary-btn {
            width: 100%;
            height: 52px;
            border: none;
            border-radius: 8px;
            background: #5c8a60;
            color: #fff;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
            margin-top: 6px;
        }

        .primary-btn:hover {
            background: #4f7a53;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 14px;
            color: #5a5a5a;
            margin: 12px 0 8px;
            font-size: 1.05rem;
            font-weight: 500;
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #b8b8b8;
        }

        .secondary-btn {
            width: 100%;
            height: 52px;
            border-radius: 8px;
            border: 1px solid #a5a5a5;
            background: rgba(255,255,255,0.08);
            color: #1a1a1a;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .school-mark {
            font-size: 1.2rem;
        }

        @media (max-width: 920px) {
            .login-shell {
                flex-direction: column;
                gap: 28px;
                width: min(520px, 90vw);
            }

            .illustration-wrap {
                min-height: auto;
            }

            .brain-illustration {
                width: min(360px, 70vw);
            }

            .login-card {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>
<body>
    <div class="bg-shape left"></div>
    <div class="bg-shape right"></div>

    <div class="login-shell">
        <div class="illustration-wrap" aria-hidden="true">
            <svg class="brain-illustration" viewBox="0 0 520 420" xmlns="http://www.w3.org/2000/svg">
                <g fill="#74a66e">
                    <g fill="#63a568">
                        <path d="M118 108c-22 8-38 23-48 43-11 22-12 49-4 72 8 22 21 39 41 49 16 9 35 14 54 13 8-1 17-3 24-8-9-15-18-30-25-47-8-20-11-41-9-62 1-16 7-31 16-44-19 0-36 4-49 10zm238 0c19 4 36 11 49 21-6 16-10 31-11 47-2 21 1 41 9 61 7 17 17 32 25 47 6 4 15 7 23 8 19 1 38-4 54-13 20-10 33-27 41-49 8-23 7-50-4-72-10-20-26-35-48-43-12-6-30-10-49-10-10 0-19 1-27 3 7 8 13 15 17 25 8 18 8 37 4 56-7 31-28 57-55 72-3 2-7 4-11 5 3-10 5-20 5-31 0-24-10-47-28-64 20-9 33-20 36-31 10-3 18-7 28-10z"/>
                    </g>
                    <g fill="#83bd7a">
                        <path d="M220 88c-56 0-102 41-108 95-6 50 17 94 58 118 18 11 34 16 50 16 18 0 36-7 54-21 15-11 27-26 35-42 19-41 5-91-34-118-18-13-35-20-55-20zm105 0c-34 0-59 17-79 40-5 8-10 17-14 27 23-6 48-7 71-2 28 7 53 23 70 47 13 18 21 40 21 62 15-15 25-36 28-58 6-51-27-94-81-116-7-4-17-7-26-7z"/>
                    </g>
                    <path d="M200 145c-22 0-45 7-61 23-13 13-20 30-22 50-2 31 8 64 31 86 18 17 42 27 67 28 15 1 29-3 42-11 28-17 46-48 47-82 1-36-19-69-52-86-17-10-34-8-52-8zm119 4c20 0 39 7 55 19 25 18 42 49 42 81 0 36-22 69-57 87-18 9-39 11-58 6-19-4-37-16-49-32-15-21-21-47-17-72 6-38 37-71 75-84 7-3 15-5 9-5z" fill="#7fbf72"/>
                    <path d="M170 181c-19-5-40-2-58 8-23 14-38 39-38 66 0 32 19 60 48 72 17 7 36 7 53 1 18-7 32-21 41-39-16 4-32 1-46-8-20-12-32-34-29-57 3-21 19-40 40-53 11-8 22-13 36-16-10-2-22-2-47-1zm182 0c18-5 38-4 56 6 24 13 39 38 41 66 2 30-14 59-42 75-19 11-42 16-63 11-15-4-29-14-39-27 14 2 29-1 42-8 23-11 38-34 37-59 0-25-16-48-38-59-13-7-27-11-41-13 9-1 25-1 47 8z" fill="#70a96c" opacity="0.7"/>
                    <path d="M217 156c22-30 61-49 100-47 18 1 35 8 49 20 20 18 31 43 32 69 1 37-18 72-52 92-18 11-39 16-61 14-22-2-43-13-58-31-18-22-25-52-20-80 4-18 17-33 33-37-3-1-7-1-23 0zm-13 66c-18 2-34 11-46 25-17 20-23 48-17 73 4 17 15 32 31 42 21 13 47 17 72 11 24-6 45-23 56-44 11-21 12-46 2-68-12-25-36-42-64-48-12-3-25-4-34-1z" fill="#8cd77f" opacity="0.75"/>
                </g>
                <g fill="#4f8f58">
                    <path d="M168 310c24-18 44-42 61-69-20 10-39 13-59 12-18-1-34-8-48-18 8 23 24 42 46 60z"/>
                    <path d="M343 310c-22-18-42-42-59-69 20 10 39 13 59 12 18-1 34-8 48-18-8 23-24 42-48 60z"/>
                    <path d="M126 286c-17-7-32-19-44-34 15 1 29 1 42-2 18-5 31-16 42-31 5 15 6 32 2 48-8 27-25 46-50 58-3 1-6 2-10 1 4-10 9-21 18-40z"/>
                    <path d="M388 286c18-7 33-19 45-34-15 1-30 1-43-2-18-5-31-16-42-31-5 15-6 32-2 48 8 27 25 46 50 58 3 1 6 2 10 1-4-10-9-21-18-40z"/>
                    <path d="M230 240c-2 46 36 83 81 83 45 0 82-37 82-83 0-41-31-71-74-76-53-6-91 36-89 76zm-4 0c0 59-50 101-101 101-25 0-49-10-67-28 19-3 37-10 52-22 15-12 26-28 31-46 8-33 4-64-15-89 11-4 24-6 36-6 57 0 88 48 64 90z" fill="#6eb775" opacity="0.65"/>
                </g>
                <g fill="#6fc174">
                    <path d="M94 252c-12 17-19 36-20 58-1 22 6 43 20 60 14 18 34 31 57 36 3 0 7 1 11 1-16-17-27-38-31-61-6-36 5-71 35-97-27 0-49 1-72 3zm339 0c13 17 20 36 21 58 1 22-6 43-20 60-14 18-34 31-57 36-3 0-7 1-11 1 16-17 27-38 31-61 6-36-5-71-35-97 27 0 49 1 72 3z"/>
                    <path d="M302 128c20 14 37 34 46 57 6 16 9 34 7 52-2 29-13 56-34 74-41 35-103 41-150 15-21-12-38-30-49-52 10 2 21 3 31 3 22 0 43-6 61-18 29-19 49-47 59-79 6-20 20-40 29-52z"/>
                    <path d="M207 113c-12 13-22 28-29 45-9 21-12 44-8 66 5 30 24 57 51 73 18 11 39 17 61 17 24 0 46-8 64-24 11-11 20-24 27-38-32 13-67 18-103 14-44-5-84-28-108-67-10-16-17-34-19-53-1-10 0-19 2-29 10-3 17-5 29-5 17 0 36 5 33 1z"/>
                </g>
                <g fill="#9ee38d">
                    <path d="M146 126l10-22c8-18 25-18 34-2l8 16-8 43-31 4-13-39zm208 0l-10-22c-8-18-25-18-34-2l-8 16 8 43 31 4 13-39z"/>
                    <path d="M187 79c12-11 31-17 50-16 20 1 38 12 49 29 3 6 0 13-7 15-20 5-38 6-58 5-12 0-24-4-34-11-4-2-5-8 0-22z"/>
                    <path d="M325 79c-12-11-31-17-50-16-20 1-38 12-49 29-3 6 0 13 7 15 20 5 38 6 58 5 12 0 24-4 34-11 4-2 5-8 0-22z"/>
                </g>
            </svg>
        </div>

        <div class="login-card">
            <h1 class="login-title">Welcome Back!</h1>
            <p class="login-subtitle">Sign in to your counselor account</p>

            <form class="login-form" action="authenticate.php" method="POST">
                <div class="field">
                    <div class="input-wrap">
                        <input type="email" name="email" placeholder="Email Address" required>
                    </div>
                </div>

                <div class="field">
                    <div class="input-wrap">
                        <input id="passwordInput" type="password" name="password" placeholder="Password" required>
                        <button type="button" class="password-toggle" aria-label="Show password" onclick="togglePassword()">◉</button>
                    </div>
                </div>

                <div class="row">
                    <label class="remember">
                        <input type="checkbox" name="remember_me">
                        <span>Remember me</span>
                    </label>
                    <a href="#" class="forgot">Forgot Password?</a>
                </div>

                <button class="primary-btn" type="submit">Login</button>

                <div class="divider">or</div>

                <button class="secondary-btn" type="button">
                    <span class="school-mark">🎓</span>
                    <span>Login with PTC Account</span>
                </button>
            </form>
        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('passwordInput');
            const btn = document.querySelector('.password-toggle');
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.textContent = isHidden ? '◌' : '◉';
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        }
    </script>
</body>
</html>