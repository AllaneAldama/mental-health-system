
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#F8F9F5">

    <title>Login | PTC Wellness</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>
        .login-feedback {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            width: 100%;
            box-sizing: border-box;
            margin: 20px 0;
            padding: 15px 16px;
            border: 1px solid transparent;
            border-radius: 12px;
            text-align: left;
            animation: feedbackEnter .3s ease both;
        }

        .login-feedback[hidden] {
            display: none;
        }

        .login-feedback.error {
            background: #fff5f4;
            border-color: #f3d2ce;
            color: #a7352e;
        }

        .login-feedback.success {
            background: #f0f8f0;
            border-color: #d0e7d0;
            color: #2f7042;
        }

        .login-feedback-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 30px;
            width: 30px;
            height: 30px;
            box-sizing: border-box;
            margin-top: 1px;
            border-radius: 50%;
            background: #f9dfdc;
            color: #a7352e;
            font-size: 17px;
            font-weight: 700;
        }

        .login-feedback.error .login-feedback-icon::before {
            content: "!";
        }

        .login-feedback.success .login-feedback-icon {
            background: #d9edd9;
            color: #2f7042;
        }

        .login-feedback.success .login-feedback-icon::before {
            content: "✓";
        }

        .login-feedback-title {
            display: block;
            margin: 2px 0 5px;
            color: inherit;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.4;
        }

        .login-feedback-message {
            margin: 0;
            color: #6b625f;
            font-size: 12px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .login-feedback.shake {
            animation: feedbackShake .35s ease;
        }

        .login-button:disabled {
            cursor: wait;
            opacity: .75;
        }

        @keyframes feedbackEnter {
            from {
                opacity: 0;
                transform: translateY(-7px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes feedbackShake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            50% { transform: translateX(4px); }
            75% { transform: translateX(-2px); }
        }

        @media (prefers-reduced-motion: reduce) {
            .login-feedback,
            .login-feedback.shake {
                animation: none;
            }
        }
    </style>
</head>

<body class="login-page">

    <main class="login-layout">

        <section class="login-visual">
            <div class="login-visual-copy">
                <p class="login-eyebrow">PTC WELLNESS</p>

                <h1>Welcome back!</h1>

                <h2>
                    Your well-being matters.
                    We're here for you.
                </h2>

                <p class="login-description">
                    Sign in securely to access the Mental Health
                    Assessment and Referral System.
                </p>
            </div>

            <div class="login-illustration">
                <img
                    src="../assets/images/brainalter.png"
                    alt="Illustration of a brain supported by green hands and leaves"
                >
            </div>

            <div class="login-privacy">
                <span class="privacy-lock" aria-hidden="true"></span>

                <div>
                    <strong>Your privacy matters.</strong>
                    <p>
                        Your account and assessment information
                        should only be accessed by authorized users.
                    </p>
                </div>
            </div>
        </section>

        <section class="login-form-section">

            <div class="login-form-card">

                <div class="login-mobile-brand">
                    <p>PTC WELLNESS</p>
                </div>

                <div class="login-heading">
                    <h2>Sign in to your account</h2>
                    <p>Enter your credentials to continue.</p>
                </div>

                <form id="login-form" action="authenticate.php" method="POST">

                    <div
                        id="login-feedback"
                        class="login-feedback"
                        role="alert"
                        aria-live="polite"
                        hidden
                    >
                        <span class="login-feedback-icon" aria-hidden="true"></span>

                        <div>
                            <strong class="login-feedback-title"></strong>
                            <p class="login-feedback-message"></p>
                        </div>
                    </div>

                    <div class="login-form-group">
                        <label for="email">Email address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="Enter your email"
                            autocomplete="username"
                            required
                        >
                    </div>

                    <div class="login-form-group">
                        <label for="password">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <button class="login-button" id="login-submit" type="submit">
                        <span class="login-button-text">Log In</span>
                        <span aria-hidden="true">→</span>
                    </button>

                </form>

                <p class="login-form-note">
                    Access is restricted to authorized users.
                </p>

            </div>

            <footer class="login-footer">
                Pateros Technological College
                <span>Supporting student well-being through care and guidance.</span>
            </footer>

        </section>

    </main>

    <script>
    (function () {
        const form = document.getElementById('login-form');
        const email = document.getElementById('email');
        const password = document.getElementById('password');
        const button = document.getElementById('login-submit');
        const buttonText = button.querySelector('.login-button-text');
        const feedback = document.getElementById('login-feedback');
        const title = feedback.querySelector('.login-feedback-title');
        const message = feedback.querySelector('.login-feedback-message');

        let submitting = false;

        function showFeedback(type, heading, text) {
            feedback.className = 'login-feedback ' + type;
            title.textContent = heading;
            message.textContent = text;
            feedback.hidden = false;

            void feedback.offsetWidth;

            if (type === 'error') {
                feedback.classList.add('shake');
            }
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (submitting) return;

            if (!email.value.trim() || !password.value) {
                showFeedback(
                    'error',
                    'Missing information',
                    'Please enter your email address and password.'
                );
                return;
            }

            submitting = true;
            button.disabled = true;
            buttonText.textContent = 'Signing in...';
            feedback.hidden = true;

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    showFeedback(
                        'error',
                        'Login unsuccessful',
                        result.message ||
                        'Email or password is incorrect. Please try again.'
                    );

                    password.value = '';
                    password.focus();
                    return;
                }

                showFeedback(
                    'success',
                    'Login successful!',
                    'Your credentials have been verified. Redirecting to your dashboard...'
                );

                if (result.redirect) {
                    window.setTimeout(function () {
                        window.location.assign(result.redirect);
                    }, 1000);
                } else {
                    showFeedback(
                        'error',
                        'Unable to continue',
                        'The login succeeded, but no dashboard destination was provided.'
                    );
                }

            } catch (error) {
                showFeedback(
                    'error',
                    'Connection error',
                    'Unable to communicate with the server. Please try again.'
                );
            } finally {
                submitting = false;
                button.disabled = false;
                buttonText.textContent = 'Log In';
            }
        });
    })();
    </script>

</body>
</html>
