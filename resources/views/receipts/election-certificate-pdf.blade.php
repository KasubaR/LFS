<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Election Certificate — {{ $election->title }}</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
    h1 { font-size: 20px; }
    h2 { font-size: 14px; margin-top: 18px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
  </style>
</head>
<body>
  <h1>LFS Election Certificate</h1>
  <p><strong>{{ $election->title }}</strong></p>
  <p>Type: {{ \App\Enums\ElectionType::label($election->type) }}</p>
  <p>Opened: {{ $election->opened_at }} · Closed: {{ $election->closed_at }}</p>
  <p>Status: {{ \App\Enums\ElectionStatus::label($election->status) }}</p>

  @foreach($tally['positions'] as $pos)
    <h2>{{ $pos['title'] }}</h2>
    <table>
      <thead><tr><th>Candidate</th><th>Votes</th></tr></thead>
      <tbody>
        @foreach($pos['candidates'] as $c)
          <tr><td>{{ $c['name'] }}</td><td>{{ $c['votes'] }}</td></tr>
        @endforeach
        <tr><td>Abstentions</td><td>{{ $pos['abstentions'] }}</td></tr>
        <tr><td>Rejected</td><td>{{ $pos['rejected'] }}</td></tr>
        <tr><td>Incomplete</td><td>{{ $pos['incomplete'] }}</td></tr>
        <tr><td>Winner (simple majority)</td><td>{{ $pos['winner']['name'] ?? 'Tie / none' }}</td></tr>
      </tbody>
    </table>
  @endforeach

  <h2>Certifications</h2>
  <ul>
    @foreach($certifications as $cert)
      <li>{{ $cert->admin?->name ?? ('Admin #'.$cert->admin_user_id) }} — {{ $cert->certified_at }}</li>
    @endforeach
  </ul>
</body>
</html>
