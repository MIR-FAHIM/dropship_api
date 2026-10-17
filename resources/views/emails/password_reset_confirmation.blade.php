<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Confirmation</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f7fa;
            margin: 0;
            padding: 0;
            color: #334155;
        }
        .container {
            max-width: 560px;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .header {
            background: #0f172a;
            color: #ffffff;
            padding: 24px 32px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }
        .content {
            padding: 32px;
        }
        .greeting {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #0f172a;
        }
        .text {
            font-size: 14px;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 20px;
        }
        .success-box {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-left: 4px solid #10b981;
            padding: 16px;
            border-radius: 6px;
            color: #065f46;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .details {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 24px;
        }
        .security-warning {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 14px 16px;
            border-radius: 6px;
            font-size: 13px;
            color: #991b1b;
        }
        .footer {
            background: #f8fafc;
            padding: 16px 32px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Security Notification</h1>
        </div>
        <div class="content">
            <div class="greeting">Hello{{ $userName ? ' ' . $userName : '' }},</div>

            <div class="success-box">
                <strong>Password Successfully Changed</strong><br>
                Your account password was updated on <strong>{{ $resetTime }}</strong>.
            </div>

            <p class="text">
                For security reasons, active sessions and login tokens have been revoked. You can now log into your account using your new password.
            </p>

            <div class="security-warning">
                <strong>Did not make this change?</strong><br>
                If you did not reset your password, your account may be compromised. Please contact support immediately.
            </div>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} ResellerBrain. All rights reserved.
        </div>
    </div>
</body>
</html>
