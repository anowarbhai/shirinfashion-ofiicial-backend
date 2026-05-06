<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Contact Message</title>
</head>
<body style="margin:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#101828;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f4f6fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:680px;background:#ffffff;border:1px solid #e5eaf3;border-radius:18px;overflow:hidden;box-shadow:0 18px 44px rgba(16,24,40,0.08);">
                    <tr>
                        <td style="padding:26px 28px;background:#101828;color:#ffffff;">
                            <p style="margin:0;color:#ff7c9c;font-size:12px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;">Shirin Fashion</p>
                            <h1 style="margin:8px 0 0;font-size:25px;line-height:1.3;font-weight:700;">New Contact Form Message</h1>
                            <p style="margin:8px 0 0;color:#d0d5dd;font-size:14px;line-height:1.6;">A customer submitted a message from the website contact page.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border:1px solid #e5eaf3;border-radius:14px;overflow:hidden;">
                                <tr>
                                    <td style="padding:16px 18px;background:#fff5f8;border-bottom:1px solid #f7d7e0;">
                                        <p style="margin:0;color:#ff2b61;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Customer Details</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:16px 18px;">
                                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-size:14px;line-height:1.65;">
                                            <tr>
                                                <td style="padding:7px 0;color:#667085;width:130px;">Name</td>
                                                <td style="padding:7px 0;font-weight:700;color:#101828;">{{ $contactMessage->name }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:7px 0;color:#667085;">Email</td>
                                                <td style="padding:7px 0;">
                                                    <a href="mailto:{{ $contactMessage->email }}" style="color:#ff2b61;text-decoration:none;font-weight:700;">{{ $contactMessage->email }}</a>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:7px 0;color:#667085;">Phone</td>
                                                <td style="padding:7px 0;color:#101828;">{{ $contactMessage->phone }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:7px 0;color:#667085;">Subject</td>
                                                <td style="padding:7px 0;font-weight:700;color:#101828;">{{ $contactMessage->subject }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin-top:20px;padding:20px;border:1px solid #e5eaf3;border-radius:14px;background:#fbfcff;">
                                <p style="margin:0 0 10px;color:#667085;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Message</p>
                                <div style="white-space:pre-line;color:#101828;font-size:15px;line-height:1.75;">{{ $contactMessage->message }}</div>
                            </div>

                            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:22px;">
                                <tr>
                                    <td>
                                        <a href="mailto:{{ $contactMessage->email }}?subject=Re:%20{{ rawurlencode($contactMessage->subject) }}" style="display:inline-block;background:#ff2b61;color:#ffffff;text-decoration:none;border-radius:999px;padding:13px 22px;font-size:14px;font-weight:700;">Reply to Customer</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:22px 0 0;color:#98a2b3;font-size:12px;line-height:1.6;">
                                Message ID: #{{ $contactMessage->id }}<br>
                                Submitted: {{ $contactMessage->created_at?->format('Y-m-d H:i:s') }}<br>
                                IP Address: {{ $contactMessage->ip_address ?: 'N/A' }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e5eaf3;">
                            <p style="margin:0;color:#667085;font-size:12px;line-height:1.6;">This email was generated automatically from the Shirin Fashion contact form. You can manage all messages from the admin contact messages page.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
