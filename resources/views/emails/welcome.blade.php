<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 40px auto; padding: 20px;">

    <h2 style="color: #1d4ed8;">Welcome to NostosEMR</h2>

    <p>Hi {{ $user->first_name }},</p>

    <p>
        An IT Admin has created your NostosEMR account.
        You can sign in using your email address and the one-time passcode (OTP) flow.
    </p>

    <table style="background: #f3f4f6; border-radius: 8px; padding: 16px; margin: 20px 0; width: 100%;">
        <tr><td><strong>Email:</strong></td><td>{{ $user->email }}</td></tr>
        <tr><td><strong>Department:</strong></td><td>{{ ucwords(str_replace('_', ' ', $user->department)) }}</td></tr>
    </table>

    <p>
        To sign in, go to your NostosEMR portal and enter your email address.
        You will receive a 6-digit code at this address to complete sign-in.
    </p>

    <p style="color: #6b7280; font-size: 0.85em;">
        If you did not expect this email, please contact your IT Admin immediately.
    </p>

    <p>— NostosEMR System</p>

</body>
</html>
