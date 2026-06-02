{{ $data['subject_line'] }}

Hi {{ $data['recipient_name'] }},

{{ $data['summary'] }}

Document details:
@foreach ($data['details'] as $detail)
- {{ $detail['label'] }}: {{ $detail['value'] }}
@endforeach

Open here: {{ $data['action_url'] }}

Delivery & privacy:
- You are receiving this email because your account is tied to an active DRS document workflow.
- This is a transactional message. To reduce optional notifications, email {{ $data['support_email'] }} and include "unsubscribe" in the subject.
- Required workflow notices may still be sent for active documents.

{{ $data['footer_note'] }}
