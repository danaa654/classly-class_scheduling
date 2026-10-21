<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your CLASSLY account</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="background:#0f172a;padding:20px 28px;color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:1px;">
                        CLASSLY
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;">
                        <p style="margin:0 0 14px;font-size:16px;">Hello {{ $name }},</p>

                        <p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
                            {{ $schoolName }} has created a CLASSLY account for you. With it you can log in and see
                            <strong>your own teaching schedule</strong>.
                        </p>

                        <p style="margin:0 0 22px;font-size:14px;line-height:1.6;">
                            To start, choose your own password using the button below.
                        </p>

                        <p style="margin:0 0 24px;text-align:center;">
                            <a href="{{ $url }}"
                               style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;padding:12px 28px;border-radius:8px;">
                                Set my password
                            </a>
                        </p>

                        <p style="margin:0 0 6px;font-size:12px;color:#64748b;line-height:1.6;">
                            This link works for {{ $hours }} hours and can be used once. If it expires, ask the
                            Registrar's office to send you a new one.
                        </p>
                        <p style="margin:0 0 6px;font-size:12px;color:#64748b;line-height:1.6;">
                            Button not working? Copy this address into your browser:<br>
                            <span style="word-break:break-all;color:#2563eb;">{{ $url }}</span>
                        </p>
                        <p style="margin:14px 0 0;font-size:12px;color:#64748b;line-height:1.6;">
                            Didn't expect this email? You can ignore it &mdash; nothing happens unless the link is used.
                        </p>

                        @if ($sentByName)
                            <p style="margin:22px 0 0;font-size:13px;line-height:1.6;">
                                Sent by {{ $sentByName }}<br>
                                {{ $schoolName }}
                            </p>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>