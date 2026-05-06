<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>New contact message</title>
</head>
<body style="margin:0;background:#f7f7f8;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f7f7f8;padding:24px 12px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="padding:22px 24px;background:#ff2b61;color:#ffffff;">
                            <h1 style="margin:0;font-size:22px;line-height:1.3;">New contact message</h1>
                            <p style="margin:6px 0 0;font-size:14px;opacity:.9;">A customer submitted the website contact form.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-size:14px;line-height:1.6;">
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;width:120px;">Name</td>
                                    <td style="padding:8px 0;font-weight:700;">{{ $contactMessage->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Email</td>
                                    <td style="padding:8px 0;">
                                        <a href="mailto:{{ $contactMessage->email }}" style="color:#ff2b61;">{{ $contactMessage->email }}</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Phone</td>
                                    <td style="padding:8px 0;">{{ $contactMessage->phone }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Subject</td>
                                    <td style="padding:8px 0;font-weight:700;">{{ $contactMessage->subject }}</td>
                                </tr>
                            </table>

                            <div style="margin-top:18px;padding:18px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
                                <p style="margin:0 0 8px;color:#6b7280;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Message</p>
                                <div style="white-space:pre-line;font-size:15px;line-height:1.7;">{{ $contactMessage->message }}</div>
                            </div>

                            <p style="margin:18px 0 0;color:#6b7280;font-size:12px;line-height:1.6;">
                                IP: {{ $contactMessage->ip_address ?: 'N/A' }}<br>
                                Submitted: {{ $contactMessage->created_at?->format('Y-m-d H:i:s') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
