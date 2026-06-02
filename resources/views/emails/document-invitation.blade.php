<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $data['subject_line'] }}</title>
</head>
<body style="margin:0;padding:0;background:#f2f4f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f8;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #d9e0ea;border-radius:10px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="background:#10233d;padding:28px 32px;color:#ffffff;">
                            <div style="font-size:12px;letter-spacing:0.08em;text-transform:uppercase;opacity:0.8;">{{ $data['portal_name'] }}</div>
                            <div style="font-size:28px;line-height:1.2;font-weight:700;margin-top:8px;">{{ $data['type_label'] }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.7;">Hi {{ $data['recipient_name'] }},</p>
                            <p style="margin:0 0 18px;font-size:15px;line-height:1.7;">
                                {{ $data['intro_paragraph'] }}
                            </p>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.7;">
                                {{ $data['review_note'] }}
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 24px;">
                                <tr>
                                    <td colspan="2" style="padding:0 0 12px;font-size:14px;font-weight:700;color:#0f172a;">Document details</td>
                                </tr>
                                @foreach ($data['details'] as $detail)
                                    <tr>
                                        <td style="padding:10px 12px;border-top:1px solid #e2e8f0;background:#f8fafc;width:36%;font-size:13px;font-weight:700;color:#334155;vertical-align:top;">
                                            {{ $detail['label'] }}
                                        </td>
                                        <td style="padding:10px 12px;border-top:1px solid #e2e8f0;font-size:13px;line-height:1.6;color:#0f172a;">
                                            {{ $detail['value'] }}
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $data['action_url'] }}" style="display:inline-block;background:#0f4c81;color:#ffffff;text-decoration:none;padding:14px 26px;border-radius:8px;font-size:15px;font-weight:700;letter-spacing:0.01em;">
                                            {{ $data['action_label'] }}
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 20px;font-size:13px;line-height:1.7;color:#475569;text-align:center;">
                                You may be asked to sign in before the document opens. The link above is tied to your invitation and should be used only by you.
                            </p>

                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px 18px;margin:0 0 20px;">
                                <div style="font-size:14px;font-weight:700;color:#0f172a;margin:0 0 10px;">Next steps</div>
                                <ul style="margin:0;padding-left:20px;font-size:13px;line-height:1.8;color:#334155;">
                                    @foreach ($data['instructions'] as $instruction)
                                        <li>{{ $instruction }}</li>
                                    @endforeach
                                </ul>
                            </div>

                            <p style="margin:0;font-size:13px;line-height:1.7;color:#475569;">
                                If you have trouble accessing the document, contact {{ $data['support_email'] }}.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 28px;font-size:12px;line-height:1.6;color:#64748b;border-top:1px solid #e2e8f0;background:#fbfdff;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#0f172a;margin:0 0 10px;">
                                Delivery &amp; privacy
                            </div>
                            <p style="margin:0 0 10px;">
                                You are receiving this email because your address is connected to an active DRS document workflow.
                            </p>
                            <p style="margin:0 0 10px;">
                                This is a transactional message. To reduce optional notifications, contact
                                <a href="mailto:{{ $data['support_email'] }}?subject={{ rawurlencode('Unsubscribe from DRS workflow notifications') }}" style="color:#0f4c81;text-decoration:none;">
                                    {{ $data['support_email'] }}
                                </a>
                                and include "unsubscribe" in the subject.
                            </p>
                            <p style="margin:0;">
                                {{ $data['footer_note'] }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
