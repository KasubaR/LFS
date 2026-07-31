@extends('layouts.app')

@section('content')
@php /* pages/error.php — Generic error page
   Locals: $status (int), $message (string)
*/ @endphp
<section style="
  min-height: 60vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 4rem 2rem;
  font-family: sans-serif;
">
    <h1 style="font-size: 6rem; margin: 0; color: #e85d04;">
        <?= (int)($status ?? 500) @endphp
    </h1>
    <h2 style="font-size: 1.5rem; margin: 1rem 0;">Something went wrong</h2>
    <p style="color: #666; max-width: 480px; margin-bottom: 2rem;">
        {{ $message ?? 'An unexpected error occurred. Please try again shortly.' }}
    </p>
    <a href="/" style="
    background: #e85d04;
    color: #fff;
    padding: 0.75rem 2rem;
    border-radius: 4px;
    text-decoration: none;
    font-weight: bold;
  ">← Back to Home</a>
</section>

@endsection
