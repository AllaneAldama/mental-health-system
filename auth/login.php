<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
</head>
<body>

    <h2>Login</h2>

    <form action="authenticate.php" method="POST">

        <label>Email</label>
        <input type="email" name="email" required>

        <label>Password</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit">Login</button>

    </form>

</body>
</html>