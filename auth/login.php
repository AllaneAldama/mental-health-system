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
            <img class="brain-illustration" src="brain-removebg-preview.png" alt="Mental health support logo">
        </div>

        <div class="login-card">
            <h1 class="login-title">Welcome Back!</h1>
            <p class="login-subtitle">Sign in to your account</p>

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