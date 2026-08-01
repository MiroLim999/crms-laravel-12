{{--
    Stand-in for a feature scheduled in a later slice. The route and its gate are
    already live; only the implementation is pending.
--}}
@extends('layouts.app')

@section('title', $title)

@section('content')
    <x-page-header :title="$title" :subtitle="$description" />

    <x-card>
        <x-empty-state
            icon="bx-wrench"
            title="Not built yet"
            message="This route, its permissions, and its place in the sidebar are wired up. The feature itself lands in a later slice." />
    </x-card>
@endsection
