<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Code</title>
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
            letter-spacing: -0.025em;
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
            margin-bottom: 24px;
        }
        .otp-wrapper {
            text-align: center;
            margin: 28px 0;
        }
        .otp-box {
            display: inline-block;
            background: #f1f5f9;
            border: 2px dashed #cbd5e1;
            border-radius: 10px;
            padding: 16px 36px;
            font-size: 32px;
            font-weight: 800;
            letter-spacing: 8px;
            color: #1e293b;
            font-family: 'Courier New', Courier, monospace;
        }
        .expiry-alert {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 13px;
            color: #92400e;
            margin-bottom: 24px;
        }
        .security-note {
            font-size: 12px;
            line-height: 1.5;
            color: #64748b;
            border-top: 1px solid #f1f5f9;
            padding-top: 20px;
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
            <h1>ResellerBrain Security</h1>
        </div>
        <div class="content">
            <div class="greeting">Hello{{ $userName ? ' ' . $userName : '' }},</div>
            <p class="text">
                We received a request to reset the password for your account. Use the 6-digit verification code below to complete your password reset:
            </p>

            <div class="otp-wrapper">
                <div class="otp-box">{{ $otp }}</div>
            </div>

            <div class="expiry-alert">
                <strong>Important:</strong> This verification code is valid for <strong>{{ $expiresMinutes }} minutes</strong>. Do not share this code with anyone.
            </div>

            <div class="security-note">
                If you did not request this code, no changes have been made to your account. You can safely ignore this email, or change your password if you suspect unauthorized activity.
            </div>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} ResellerBrain. All rights reserved.
        </div>
    </div>
</body>
</html>
