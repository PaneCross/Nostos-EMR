<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your NostosEMR Sign-In Code</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; margin: 0; padding: 0; }
        .container { max-width: 520px; margin: 40px auto; background: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); overflow: hidden; }
        .header { background: #1e40af; padding: 28px 32px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 20px; letter-spacing: -0.3px; }
        .body { padding: 32px; }
        .greeting { color: #1e293b; font-size: 16px; margin-bottom: 16px; }
        .code-block { background: #f1f5f9; border: 2px dashed #cbd5e1; border-radius: 8px; text-align: center; padding: 24px; margin: 24px 0; }
        .code { font-size: 40px; font-weight: 700; letter-spacing: 12px; color: #1e40af; font-family: 'Courier New', monospace; }
        .expiry { color: #64748b; font-size: 14px; margin-top: 8px; }
        .notice { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 0 4px 4px 0; font-size: 13px; color: #92400e; margin: 20px 0; }
        .footer { background: #f8fafc; padding: 16px 32px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>NostosEMR</h1>
        </div>
        <div class="body">
            <p class="greeting">Hello, {{ $user->first_name }}.</p>
            <p style="color:#475569;font-size:15px;">
                Use the code below to sign in to NostosEMR. This code expires in
                <strong>10 minutes</strong>.
            </p>

            <div class="code-block">
                <div class="code">{{ $code }}</div>
                <div class="expiry">Expires at {{ now()->addMinutes(10)->format('g:i A T') }}</div>
            </div>

            <div class="notice">
                If you did not request this code, please ignore this email. Do not share this
                code with anyone. NostosEMR staff will never ask for your sign-in code.
            </div>

            <p style="color:#475569;font-size:14px;">
                This system contains protected health information (PHI). Unauthorized access
                is prohibited and monitored.
            </p>
        </div>
        <div class="footer">
            NostosEMR &bull; PACE Electronic Medical Records &bull;
            This is an automated message — do not reply.
        </div>
    </div>
</body>
</html>
