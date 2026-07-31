@extends('layouts.admin')

@section('content')
@php
$isEdit = false;
$postId = null;
@endphp
@include('admin.blog._form')

@endsection
