<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Database backup ready</title>
</head>
<body style="margin:0;background:#f6f7fb;font-family:Arial,sans-serif;color:#101828;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7fb;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;border:1px solid #e5e7eb;overflow:hidden;">
                    <tr>
                        <td style="padding:28px 30px 12px;">
                            <p style="margin:0 0 8px;color:#f72566;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Shirin Fashion</p>
                            <h1 style="margin:0;font-size:26px;line-height:1.25;">Database backup is ready</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 30px 6px;">
                            <p style="margin:0 0 16px;color:#475467;font-size:15px;line-height:1.7;">A database backup has been generated successfully. Keep this file private and download it only from a trusted device.</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff1f5;border-radius:14px;padding:16px;">
                                <tr>
                                    <td style="padding:6px 0;color:#667085;font-size:13px;">File</td>
                                    <td style="padding:6px 0;text-align:right;font-weight:700;">{{ $backup->filename }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#667085;font-size:13px;">Size</td>
                                    <td style="padding:6px 0;text-align:right;font-weight:700;">{{ number_format((int) $backup->size / 1024 / 1024, 2) }} MB</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#667085;font-size:13px;">Expires</td>
                                    <td style="padding:6px 0;text-align:right;font-weight:700;">{{ optional($backup->download_token_expires_at)->format('M j, Y g:i A') }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 30px 34px;">
                            <a href="{{ $downloadUrl }}" style="display:inline-block;background:#f72566;color:#ffffff;text-decoration:none;font-weight:700;border-radius:999px;padding:14px 24px;">Download backup</a>
                            <p style="margin:18px 0 0;color:#667085;font-size:12px;line-height:1.6;">If the button does not work, open this link: <br><span style="word-break:break-all;">{{ $downloadUrl }}</span></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
