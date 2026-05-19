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

{{ $data['footer_note'] }}
