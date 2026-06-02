{{ $data['subject_line'] }}

Hi {{ $data['recipient_name'] }},

{{ $data['intro_paragraph'] }}

{{ $data['review_note'] }}

Document details:
@foreach ($data['details'] as $detail)
- {{ $detail['label'] }}: {{ $detail['value'] }}
@endforeach

Open here: {{ $data['action_url'] }}

Next steps:
@foreach ($data['instructions'] as $instruction)
- {{ $instruction }}
@endforeach

If you have trouble accessing the document, contact {{ $data['support_email'] }}.

Delivery & privacy:
- You are receiving this email because your address is connected to an active DRS document workflow.
- This is a transactional message. To reduce optional notifications, email {{ $data['support_email'] }} and include "unsubscribe" in the subject.
- Required workflow notices may still be sent for active documents and invitations.

{{ $data['footer_note'] }}
