@extends('layouts.admin')

@section('content')
@php
$isEdit = true;
@endphp
@include('admin.blog._form')

@endsection
