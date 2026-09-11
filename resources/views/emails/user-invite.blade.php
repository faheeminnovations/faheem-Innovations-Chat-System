<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faheem Innovations Chat invitation</title>
</head>
<body style="margin: 0; background: #f4f7fb; color: #172033; font-family: Arial, sans-serif;">
    <div style="max-width: 560px; margin: 32px auto; padding: 32px; background: #ffffff; border-radius: 12px;">
        <h1 style="margin-top: 0; color: #172033;">Welcome to Faheem Innovations Chat</h1>
        <p>Hello {{ $userName }},</p>
        <p>An administrator created an account for you. Use these details to sign in:</p>
        <p><strong>Email:</strong> {{ $userEmail }}<br>
            <strong>Temporary password:</strong> {{ $temporaryPassword }}</p>
        <p><a href="{{ $loginUrl }}" style="display: inline-block; padding: 12px 18px; background: #2563eb; color: #ffffff; text-decoration: none; border-radius: 8px;">Open chat</a></p>
        <p style="color: #64748b;">Please change your password after signing in.</p>
    </div>
</body>
</html>
