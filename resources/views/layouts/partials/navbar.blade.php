@php
    $user = auth()->user();
@endphp

<nav class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme"
     id="layout-navbar">

    {{-- Uses the responsive off-canvas state on small screens and the desktop
         collapsed-menu state at xl and above. --}}
    <div class="navbar-nav align-items-xl-center me-4 me-xl-0">
        <button type="button"
                class="sidebar-toggle-control nav-item nav-link px-0 me-xl-6 border-0 bg-transparent"
                aria-controls="layout-menu"
                aria-label="Toggle sidebar"
                onclick="const root = document.documentElement; const desktop = window.matchMedia('(min-width: 1200px)').matches; const sidebarClass = desktop ? 'layout-menu-collapsed' : 'layout-menu-expanded'; root.classList.add('layout-transitioning'); root.classList.toggle(sidebarClass); window.setTimeout(() => root.classList.remove('layout-transitioning'), 350); if (desktop) localStorage.setItem('crms.sidebarCollapsed', String(root.classList.contains(sidebarClass)));">
            <i class="icon-base bx bx-menu icon-md"></i>
        </button>
    </div>

    <div class="navbar-nav-right d-flex align-items-center" id="navbar-collapse">

        {{-- Archive search. Every signed-in role may search records. --}}
        @can('records.view')
            <div class="navbar-nav align-items-center">
                <form action="{{ route('records.index') }}" method="GET"
                      class="nav-item d-flex align-items-center" role="search">
                    <i class="icon-base bx bx-search icon-md"></i>
                    <input type="search" name="q" value="{{ request('q') }}"
                           class="form-control border-0 shadow-none ps-1 ps-sm-2"
                           placeholder="Search records..." aria-label="Search records">
                </form>
            </div>
        @endcan

        <ul class="navbar-nav flex-row align-items-center ms-auto">
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
