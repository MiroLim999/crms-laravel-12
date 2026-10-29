@extends('layouts.app')

@section('title', 'New Document')
@section('body-class', 'document-workspace-focused')

{{-- The workspace's own frame and first step, with no type chosen yet. Choosing
     one opens the workspace, which looks the same with the drop area ready. --}}
@section('workspace-navbar')
    @include('scan.partials.topbar')
@endsection

@section('content')
    @include('scan.partials.document-step', ['currentType' => null])
@endsection
