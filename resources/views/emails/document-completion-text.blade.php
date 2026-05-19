{{ $data['subject_line'] }}

Hi {{ $data['recipient_name'] }},

{{ $data['summary'] }}

Document details:
@foreach ($data['details'] as $detail)
- {{ $detail['label'] }}: {{ $detail['value'] }}
@endforeach

Open here: {{ $data['action_url'] }}

{{ $data['footer_note'] }}

