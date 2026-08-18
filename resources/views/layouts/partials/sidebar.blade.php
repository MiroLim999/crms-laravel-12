{{-- Role-driven vertical menu. Items come from App\Support\Navigation. --}}
<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme"
       aria-label="Primary navigation">

    <div class="app-brand sidebar-brand">
        <a href="{{ route('dashboard') }}" class="app-brand-link sidebar-brand__link"
           aria-label="{{ config('app.name') }} dashboard">
            <span class="app-brand-logo sidebar-brand__mark">
                <img src="{{ asset('assets/img/crms-logo.png') }}" alt="{{ config('app.name') }} logo"
                     width="36" height="36">
            </span>
            <span class="app-brand-text menu-text sidebar-brand__name">CRMS</span>
        </a>
    </div>

    <div class="menu-divider sidebar-brand-divider"></div>
    <div class="menu-inner-shadow"></div>

    <ul class="menu-inner sidebar-nav">
        @foreach (\App\Support\Navigation::sections() as $sectionIndex => $section)
            @if ($section['header'])
                <li class="menu-header sidebar-nav__section" id="sidebar-section-{{ $sectionIndex }}">
                    <span class="menu-header-text">{{ $section['header'] }}</span>
                </li>
            @endif

            @foreach ($section['items'] as $item)
                <li class="menu-item sidebar-nav__item {{ $item['active'] ? 'active' : '' }}">
                    <a href="{{ route($item['route']) }}" class="menu-link sidebar-nav__link"
                       aria-label="{{ $item['label'] }}" title="{{ $item['label'] }}"
                       @if ($item['active']) aria-current="page" @endif>
                        <i class="menu-icon icon-base bx {{ $item['icon'] }}" aria-hidden="true"></i>
                        <span class="sidebar-nav__label">{{ $item['label'] }}</span>
                        @if (! empty($item['badge']))
                            <span class="badge rounded-pill {{ $item['badge']['class'] ?? 'bg-primary' }} ms-auto sidebar-nav__badge">{{ $item['badge']['count'] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        @endforeach
    </ul>
</aside>
