@php
    $user = auth()->user();
@endphp

<nav class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme"
     id="layout-navbar">

    <div class="navbar-nav-right d-flex align-items-center" id="navbar-collapse">

        {{-- Archive search. Every signed-in role may search records. --}}
        @can('records.view')
            <div class="navbar-nav align-items-center">
                <form action="{{ route('records.index') }}" method="GET"
                      class="nav-item d-flex align-items-center" role="search">
                    <i class="icon-base bx bx-search icon-md"></i>
                    {{-- An edited URL can send ?q[]=x, an array, which can't be printed. --}}
                    <input type="search" name="q" value="{{ is_string(request('q')) ? request('q') : '' }}"
                           class="form-control border-0 shadow-none ps-1 ps-sm-2"
                           placeholder="Search records..." aria-label="Search records">
                </form>
            </div>
        @endcan

        <ul class="navbar-nav flex-row align-items-center ms-auto">
            {{-- Colour mode: handled by resources/js/theme.js --}}
            <li class="nav-item dropdown me-2">
                <button type="button" class="btn btn-icon btn-label-secondary rounded-pill dropdown-toggle hide-arrow"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="Colour mode" title="Colour mode">
                    <i class="icon-base bx bx-sun icon-md" data-theme-current-icon aria-hidden="true"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center" data-theme-choice="light">
                            <i class="icon-base bx bx-sun icon-md me-3" aria-hidden="true"></i>Light
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center" data-theme-choice="dark">
                            <i class="icon-base bx bx-moon icon-md me-3" aria-hidden="true"></i>Dark
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center" data-theme-choice="system">
                            <i class="icon-base bx bx-desktop icon-md me-3" aria-hidden="true"></i>System
                        </button>
                    </li>
                </ul>
            </li>
            <li class="nav-item navbar-dropdown dropdown-user dropdown">
                <a class="nav-link dropdown-toggle hide-arrow p-0" href="javascript:void(0);"
                   data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar avatar-online">
                        <span class="avatar-initial rounded-circle bg-label-primary">
                            {{ $user->initials() }}
                        </span>
                    </div>
                </a>

                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <div class="dropdown-item-text">
                            <div class="d-flex">
                                <div class="flex-shrink-0 me-3">
                                    <div class="avatar avatar-online">
                                        <span class="avatar-initial rounded-circle bg-label-primary">
                                            {{ $user->initials() }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-0">{{ $user->name }}</h6>
                                    <small class="text-muted">{{ $user->role->name }}</small>
                                </div>
                            </div>
                        </div>
                    </li>
                    <li><div class="dropdown-divider my-1"></div></li>
                    <li>
                        <a class="dropdown-item" href="{{ route('settings.edit') }}">
                            <i class="icon-base bx bx-cog icon-md me-3"></i>
                            <span>Account Settings</span>
                        </a>
                    </li>
                    <li><div class="dropdown-divider my-1"></div></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item">
                                <i class="icon-base bx bx-power-off icon-md me-3"></i>
                                <span>Sign Out</span>
                            </button>
                        </form>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</nav>
